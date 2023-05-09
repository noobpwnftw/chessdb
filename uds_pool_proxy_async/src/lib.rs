use std::{
    collections::VecDeque,
    fs::Permissions,
    io::{self, ErrorKind},
    os::unix::fs::PermissionsExt,
    path::Path,
    sync::Arc,
};

use tokio::{
    fs,
    io::{AsyncReadExt, AsyncWriteExt, AsyncBufReadExt, BufReader},
    net::{UnixListener, UnixStream},
    process::{ChildStdin, ChildStdout, Command},
    sync::Mutex,
};

const BUF_SZ: usize = 64 * 1024;

#[derive(Debug)]
struct Backend {
    stdin: Option<ChildStdin>,
    stdout: Option<ChildStdout>,
    alive: bool,
    in_use: bool,
}

impl Backend {
    fn new(stdin: ChildStdin, stdout: ChildStdout) -> Self {
        Self { stdin: Some(stdin), stdout: Some(stdout), alive: true, in_use: false }
    }
}

#[derive(Clone)]
pub struct Pool {
    inner: Arc<Mutex<State>>,
}

struct State {
    pool_size: usize,
    argv: Vec<String>,
    backs: Vec<Backend>,
    idle: VecDeque<usize>, // LIFO: pop_back()
    alive_count: usize,
}

impl Pool {
    pub async fn new(pool_size: usize, argv: Vec<String>) -> io::Result<Self> {
        let pool = Pool {
            inner: Arc::new(Mutex::new(State {
                pool_size,
                argv,
                backs: Vec::with_capacity(pool_size.max(8)),
                idle: VecDeque::new(),
                alive_count: 0,
            })),
        };
        for _ in 0..pool_size {
            pool.spawn_backend().await?;
        }
        Ok(pool)
    }

    async fn spawn_backend(&self) -> io::Result<usize> {
        // spawn child using current argv
        let (mut child, stdin, stdout) = {
            let st = self.inner.lock().await;
            spawn_child(&st.argv).await?
        };

        // register it and get index
        let idx = {
            let mut st = self.inner.lock().await;
            let b = Backend::new(stdin, stdout);
            st.backs.push(b);
            let idx = st.backs.len() - 1;
            st.idle.push_back(idx);
            st.alive_count += 1;
            idx
        };

        // reaper uses index directly
        let pool = self.clone();
        tokio::spawn(async move {
            let _ = child.wait().await;
            pool.mark_dead_by_idx(idx).await;
        });

        Ok(idx)
    }

    pub async fn checkout(&self) -> io::Result<(usize, ChildStdin, ChildStdout)> {
        // try idle LIFO first
        {
            let mut st = self.inner.lock().await;
            while let Some(idx) = st.idle.pop_back() {
                if st.backs.get(idx)
                    .map(|b| b.alive && !b.in_use && b.stdin.is_some() && b.stdout.is_some())
                    .unwrap_or(false)
                {
                    let b = &mut st.backs[idx];
                    b.in_use = true;
                    return Ok((idx, b.stdin.take().unwrap(), b.stdout.take().unwrap()));
                }
            }
        }

        // none idle: spawn one
        let idx_new = self.spawn_backend().await?;
        let mut st = self.inner.lock().await;
        if let Some(pos) = st.idle.iter().position(|&i| i == idx_new) {
            st.idle.remove(pos);
        }
        let b = &mut st.backs[idx_new];
        b.in_use = true;
        Ok((idx_new, b.stdin.take().unwrap(), b.stdout.take().unwrap()))
    }

    pub async fn checkin(
        &self,
        idx: usize,
        stdin: ChildStdin,
        stdout: ChildStdout,
        recycle_ok: bool,
    ) -> io::Result<()> {
        let mut st = self.inner.lock().await;
        if idx >= st.backs.len() {
            drop(stdin);
            drop(stdout);
            return Ok(());
        }

        let over = st.alive_count > st.pool_size;

        if !recycle_ok || over {
            // ensure pipes are closed so backend exits
            drop(stdin);
            drop(stdout);

            // edit the Backend entry in its own borrow scope
            let was_alive = {
                let b = &mut st.backs[idx];
                let was_alive = b.alive;
                b.alive = false;
                b.in_use = false;
                b.stdin = None;
                b.stdout = None;
                was_alive
            };

            if was_alive {
                st.alive_count = st.alive_count.saturating_sub(1);
            }
            if let Some(pos) = st.idle.iter().position(|&i| i == idx) {
                st.idle.remove(pos);
            }
            return Ok(());
        }

        // recycle: put pipes back, mark idle (LIFO -> push_back)
        {
            let b = &mut st.backs[idx];
            b.stdin = Some(stdin);
            b.stdout = Some(stdout);
            b.in_use = false;
        }
        st.idle.push_back(idx);
        Ok(())
    }

    async fn mark_dead_by_idx(&self, idx: usize) {
        let mut st = self.inner.lock().await;
        if idx < st.backs.len() {
            // limit &mut borrow scope to this block
            let was_alive = {
                let b = &mut st.backs[idx];
                let was_alive = b.alive;
                if b.alive {
                    b.alive = false;
                    b.stdin.take();
                    b.stdout.take();
                    b.in_use = false;
                }
                was_alive
            };
            if was_alive {
                st.alive_count = st.alive_count.saturating_sub(1);
            }
            if let Some(pos) = st.idle.iter().position(|&i| i == idx) {
                st.idle.remove(pos);
            }
        }
    }
}

async fn spawn_child(argv: &[String]) -> io::Result<(tokio::process::Child, ChildStdin, ChildStdout)> {
    let prog = argv.get(0).cloned().unwrap_or_else(|| "cat".into());
    let mut cmd = Command::new(prog);
    if argv.len() > 1 {
        cmd.args(&argv[1..]);
    }
    let mut child: tokio::process::Child = cmd
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::null())
        .spawn()?;

    let stdin = child.stdin.take().ok_or_else(|| io::Error::new(ErrorKind::Other, "no child stdin"))?;
    let mut stdout = child.stdout.take().ok_or_else(|| io::Error::new(ErrorKind::Other, "no child stdout"))?;
    {
        let mut br = BufReader::new(&mut stdout);
        let mut _skip = String::new();
        let _ = br.read_line(&mut _skip).await;
    }
    Ok((child, stdin, stdout))
}

/// Run the proxy server until `shutdown_rx` fires.
pub async fn run_server(
    socket_path: String,
    pool_size: usize,
    argv: Vec<String>,
    clean_marker: String, // mandatory positional
    mut shutdown_rx: tokio::sync::oneshot::Receiver<()>,
) -> io::Result<()> {
    // prepare socket
    let p = Path::new(&socket_path);
    if p.exists() {
        let _ = fs::remove_file(&socket_path).await;
    }
    let listener = UnixListener::bind(&socket_path)?;
    fs::set_permissions(&socket_path, Permissions::from_mode(0o666)).await?;

    // pool
    let pool = Pool::new(pool_size, argv).await?;

    loop {
        tokio::select! {
            _ = &mut shutdown_rx => {
                let _ = fs::remove_file(&socket_path).await;
                // close all pipes to let backends exit
                let mut st = pool.inner.lock().await;
                for b in st.backs.iter_mut() {
                    b.stdin.take();
                    b.stdout.take();
                    b.alive = false;
                    b.in_use = false;
                }
                st.alive_count = 0;
                st.idle.clear();
                break;
            }
            Ok((stream, _)) = listener.accept() => {
                let pool_clone = pool.clone();
                let marker = clean_marker.clone();
                tokio::spawn(async move {
                    if let Err(e) = handle_client(stream, pool_clone, marker).await {
                        eprintln!("session error: {e}");
                    }
                });
            }
        }
    }
    Ok(())
}

async fn handle_client(stream: UnixStream, pool: Pool, clean_marker: String) -> io::Result<()> {
    let (idx, mut be_stdin, mut be_stdout) = pool.checkout().await?;
    let (mut cr, mut cw) = stream.into_split();

    let mut buf_in = vec![0u8; BUF_SZ];
    let mut buf_out = vec![0u8; BUF_SZ];
    let mut saw_clean_marker = false;
    let cmb = clean_marker.as_bytes();

    loop {
        tokio::select! {
            // CLIENT -> BACKEND
            r = cr.read(&mut buf_in) => {
                match r {
                    Ok(0) => break, // client done
                    Ok(n) => {
                        let mut end = n;

                        while end > 0 {
                            let b = buf_in[end - 1];
                            if b == b'\r' || b == b'\n' {
                                end -= 1;
                            } else {
                                break;
                            }
                        }

                        let mut i = end;
                        let mut j = cmb.len();
                        while j > 0 {
                            if i == 0 || buf_in[i - 1] != cmb[j - 1] {
                                break;
                            }
                            i -= 1;
                            j -= 1;
                        }

                        if j == 0 {
                            if i > 0 {
                                be_stdin.write_all(&buf_in[..i]).await?;
                            }
                            saw_clean_marker = true;
                            continue; // do not forward marker to backend
                        }
                        be_stdin.write_all(&buf_in[..n]).await?;
                    }
                    Err(ref e) if e.kind() == ErrorKind::WouldBlock => {}
                    Err(_) => break,
                }
            }
            // BACKEND -> CLIENT
            r = be_stdout.read(&mut buf_out) => {
                match r {
                    Ok(0) => break,
                    Ok(n) => {
                        if let Err(_)= cw.write_all(&buf_out[..n]).await {
                            break;
                        }
                    }
                    Err(ref e) if e.kind() == ErrorKind::WouldBlock => {}
                    Err(_) => break,
                }
            }
        }
    }

    // Recycle only if client sent the explicit clean marker this session
    pool.checkin(idx, be_stdin, be_stdout, saw_clean_marker).await
}

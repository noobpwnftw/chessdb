Usage
-----

./fqdb --socket /tmp/fqdb.sock --threads 8 --base-dir /var/lib/fqdb

This will create subdirectories automatically if missing:
  /var/lib/fqdb/qp/
  /var/lib/fqdb/qnp/

Files:
  /var/lib/fqdb/qp/data.wal
  /var/lib/fqdb/qp/data.snap
  /var/lib/fqdb/qnp/data.wal
  /var/lib/fqdb/qnp/data.snap

Notes
-----
- Handles base-dir with or without trailing '/'.
- If --base-dir is omitted, persistence is disabled (empty wal/snap paths).

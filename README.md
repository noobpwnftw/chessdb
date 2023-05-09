# ChessDB

## Info

To run the code you need to set up the following:
- PHP8+ with Redis, Memcache and Judy extensions
- A few custom PHP extensions for board operations and utilities
- KV data storage server(ssdb)
- Memcached for frontend query cache
- Frontend engine pool server(uds_pool_proxy_async)
- FlexibleQueueDB(fqdb) for worker pool task queue
- Tablebase servers(lila-tablebase for chess, for xiangqi via tbproxy.php if not local)

All custom PHP modules used are provided in source code, follow standard PHP extension building instructions to compile and install. I use an optimized version of SSDB https://github.com/noobpwnftw/ssdb, which can greatly reduce database size and increase query performance, and a fork of https://github.com/noobpwnftw/lila-tablebase with modifications to move sort order.

The frontend calls a PHP script(cdb.php, or chessdb.php for xiangqi) that handles API requests and database operations. You also need a swarm of workers that run move sieving(Sel) and scoring(Client) to consume the task queue, for those I use this fork of Stockfish https://github.com/noobpwnftw/Stockfish/tree/siever.

Castling semantics are automatically inferred from the current board state. If any castling right cannot be expressed in standard notation, castling moves(if any) are encoded using Chess960 rules. This determination is made on a per-position basis, so queries may result in move sequences with mixed interpretations. Worker engines apply the same logic, therefore a separate UCI_Chess960 option is unnecessary.

For your data integrity, it is suggested to only allow trusted processing power for these tasks. You should specify your password in the beginning of the PHP script and generate access tokens to your workers accordingly. Access tokens are IP address bound.

To further extend the database you can let anyone play against the database, and it may be *safe* to let users contribute their processing power using their own chess engines. There is a tool(Discover) for that, as only new moves and positions are added to the task queues and they will be later processed by your trusted workers.

There are a few utility tools, which are mostly for offline bootstrapping, score backpropagation and import/export in the scripts folder.

Most parameters used in score calculations are from experience, however they can be changed with ease.

Essentially everything there is for anyone to operate the project is here, minus their recurring fat bills.

## Official API & Website

The web query interface is located at:

https://www.chessdb.cn/

API endpoints are accessible here:

Chess: https://www.chessdb.cn/cdb.php

Xiangqi: https://www.chessdb.cn/chessdb.php

Also the non-HTTPS endpoint should work and it is recommended if you intend to run large amounts of queries.

- A status page showing current database statistics:

https://www.chessdb.cn/statsc.php?lang=1

- To lookup information about all known moves of starting position, try this:

https://www.chessdb.cn/cdb.php?action=queryall&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

- To let the database suggest a move for a position, try this:

https://www.chessdb.cn/cdb.php?action=querybest&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

- To let the database provide follow-up move information for a position, try this:

https://www.chessdb.cn/cdb.php?action=querypv&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

Adding an ``&json=1`` parameter will turn outputs into JSON format, along with SAN move notations for certain types of queries:

https://www.chessdb.cn/cdb.php?action=queryall&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201&json=1

Please check the corresponding source code for detailed API syntax and output format.

## Database Snapshot

Full database snapshots are available at:

ftp://chessdb:chessdb@ftp.chessdb.cn/pub/chessdb/

rsync://ftp.chessdb.cn/ftp/pub/chessdb/

There are more convenient mirrors if you know where to find them.

Same as the code, unless otherwise specified, they are released into the public domain.

---
**Expect no further documentation except this one: the code speaks for itself.**

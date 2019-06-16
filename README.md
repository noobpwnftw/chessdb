# ChessDB

## Info
To run the code you need to set up:
- MongoDB for task queue.
- Redis-compatible server for data storage.
- Memcached for frontend cache.
- A few PHP extensions for board operations and other utilities.

All modules used are provided in source code. I use an optimized version of SSDB https://github.com/noobpwnftw/ssdb, which can greatly reduce database size and increase query performance.

The frontend is a PHP script(cdb.php) that handles API requests and database operations, you also need a swarm of workers that runs move sieving(Sel) and scoring(Client), for those I use this fork of Stockfish https://github.com/noobpwnftw/Stockfish/tree/siever.

For your data integrity, it is suggested to only allow trusted processing power for these tasks, you can specify your password in the beginning of the PHP script and generate access tokens to your workers accordingly, access tokens are IP address bound.

To further extend the database you can let an engine play against the database, there is a tool(Discover) for that, and it may be *safe* to let users contribute their processing power and use their own engines.

To check your database status, there is another PHP script(statsc.php), there are also a few utilities located in scripts folder, which are mostly for bootstraping and import/export.

Most parameters used in score calculations are from experience, however they can be changed with ease.

## Official API

My API endpoint is accessable here:

http://www.chessdb.cn/cdb.php

- To lookup information about all known moves of starting position, try this:

http://www.chessdb.cn/cdb.php?action=queryall&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

- To let the database suggest a move for a position, try this:

http://www.chessdb.cn/cdb.php?action=querybest&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

- To let the database provide follow-up move information for a position, try this:

http://www.chessdb.cn/cdb.php?action=querypv&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

Please check the corresponding source code for API syntax and output format.

*** Expect no further documentation except this one but the code should be self-explanatory.

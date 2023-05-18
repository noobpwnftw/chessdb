# ChessDB

## Info

To run the code you need to set up the following:
- MongoDB 3.4(newest supported by the legacy driver) for task queue.
- A Redis-compatible server for data storage.
- Memcached for frontend query cache.
- PHP 5.x with Judy, redis, memcache and mongo(the legacy one) extensions.
- A few custom PHP extensions for board operations and other utilities.

All custom PHP modules used are provided in source code, follow standard PHP extension building instructions to compile and install. I use an optimized version of SSDB https://github.com/noobpwnftw/ssdb, which can greatly reduce database size and increase query performance.

The frontend is a PHP script(cdb.php) that handles API requests and database operations, you also need a swarm of workers that runs move sieving(Sel) and scoring(Client) to consume the task queue, for those I use this fork of Stockfish https://github.com/noobpwnftw/Stockfish/tree/siever.

For your data integrity, it is suggested to only allow trusted processing power for these tasks, you can specify your password in the beginning of the PHP script and generate access tokens to your workers accordingly, access tokens are IP address bound.

To further extend the database you can let anyone play against the database, and it may be *safe* to let users contribute their processing power using their own chess engines, there is a tool(Discover) for that, as only new moves and positions are added to the task queues and they will be later processed by your trusted workers.

To check your database status, there is another PHP script(statsc.php), there are also a few utilities located in scripts folder, which are mostly for bootstraping and import/export.

Most parameters used in score calculations are from experience, however they can be changed with ease.

## Task Queue

The following indexes of MongoDB are required to ensure proper performance:

For `cdbqueue` & `ccdbqueue`, the databases for scoring queues:
```
db.queuedb.ensureIndex({p:-1,e:1})
db.queuedb.ensureIndex({e:1},{partialFilterExpression:{p:null},expireAfterSeconds:7200})
```

For `cdbackqueue` & `ccdbackqueue`, the databases for scoring queues(in-flight):
```
db.ackqueuedb.ensureIndex({ts:1})
```

For `cdbsel` & `ccdbsel`, the databases for sieving queues:
```
db.seldb.ensureIndex({p:-1,e:-1})
db.seldb.ensureIndex({e:1},{partialFilterExpression:{p:null},expireAfterSeconds:7200})
```

For `cdbacksel` & `ccdbacksel`, the databases for sieving queues(in-flight):
```
db.ackseldb.ensureIndex({ts:1})
```

## Official API & Website

The web query interface is located at:

https://www.chessdb.cn/

API endpoints are accessable here:

Chess: https://www.chessdb.cn/cdb.php

Xiangqi: https://www.chessdb.cn/chessdb.php

Also the non-HTTPS endpoint should also work and it is recommended if you intend to run large amounts of queries.

- A status page showing current database statistics:

https://www.chessdb.cn/statsc.php?lang=1

- To lookup information about all known moves of starting position, try this:

https://www.chessdb.cn/cdb.php?action=queryall&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

- To let the database suggest a move for a position, try this:

https://www.chessdb.cn/cdb.php?action=querybest&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

- To let the database provide follow-up move information for a position, try this:

https://www.chessdb.cn/cdb.php?action=querypv&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201

Adding ``&json=1`` parameter will turn outputs into JSON format, along with SAN move notations for certain types of queries:

https://www.chessdb.cn/cdb.php?action=queryall&board=rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR%20w%20KQkq%20-%200%201&json=1

Please check the corresponding source code for detailed API syntax and output format.

## Database Snapshot

Full database snapshots are available upon request, it is no longer trivial to distribute the online database due to size.

*** Expect no further documentation except this one: the code speaks for itself.

#!/usr/bin/python3

import chess
import chess.polyglot

def move_to_polyglot_int(board, move):
    to_square = move.to_square
    from_square = move.from_square
    # Polyglot encodes castling moves with the from_square being where the king started
    # and the to_square being where the rook started, instead of the UCI standard of the
    # to_square being where the king ends up. Patch up this encoding.
    if board.is_castling(move):
        to_square = {
            chess.G1: chess.H1,
            chess.C1: chess.A1,
            chess.G8: chess.H8,
            chess.C8: chess.A8,
        }[to_square]
    promotion = {
        None: 0,
        chess.KNIGHT: 1,
        chess.BISHOP: 2,
        chess.ROOK: 3,
        chess.QUEEN: 4,
    }[move.promotion]
    return to_square | (from_square << 6) | (promotion << 12)


def make_entry(board, move, weight=1, learn=0):
    key = chess.polyglot.zobrist_hash(board)
    raw_move = move_to_polyglot_int(board, move)
    return chess.polyglot.Entry(key=key, raw_move=raw_move, weight=weight, learn=learn)


def write_polyglot_bin(f, entries):
    entries = sorted(entries, key=lambda entry: entry.key)
    for entry in entries:
        f.write(chess.polyglot.ENTRY_STRUCT.pack(*entry))

if __name__ == "__main__":
    entries = []
    with open("dump.txt", "rb") as f:
        while True:
            line = f.readline()
            if line is None or len(line) <= 2:
                break
            entry = line.decode("utf-8").split()
            board = chess.Board(entry[0] + " " + entry[1] + " " + entry[2] + " " + entry[3] + " 0 1")
            move = chess.Move.from_uci(entry[4])
            weight = entry[5]
            entries.append(make_entry(board, move, int(weight)))
    with open("book.bin", "wb") as f:
        write_polyglot_bin(f, entries)

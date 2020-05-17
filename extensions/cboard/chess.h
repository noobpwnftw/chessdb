#ifndef CHESSLIB_CHESS_H_
#define CHESSLIB_CHESS_H_
#pragma warning(disable : 4996)
typedef enum
{
    CHESS_FALSE = 0,
    CHESS_TRUE = 1
} ChessBoolean;

typedef enum
{
    CHESS_COLOR_WHITE = 0,
    CHESS_COLOR_BLACK = 1
} ChessColor;

typedef enum
{
    CHESS_PIECE_NONE = 0,
    CHESS_PIECE_WHITE_PAWN = 2,
    CHESS_PIECE_BLACK_PAWN = 3,
    CHESS_PIECE_WHITE_KNIGHT = 4,
    CHESS_PIECE_BLACK_KNIGHT = 5,
    CHESS_PIECE_WHITE_BISHOP = 6,
    CHESS_PIECE_BLACK_BISHOP = 7,
    CHESS_PIECE_WHITE_ROOK = 8,
    CHESS_PIECE_BLACK_ROOK = 9,
    CHESS_PIECE_WHITE_QUEEN = 10,
    CHESS_PIECE_BLACK_QUEEN = 11,
    CHESS_PIECE_WHITE_KING = 12,
    CHESS_PIECE_BLACK_KING = 13
} ChessPiece;

typedef enum
{
    CHESS_FILE_INVALID = -1,
    CHESS_FILE_A = 0,
    CHESS_FILE_B,
    CHESS_FILE_C,
    CHESS_FILE_D,
    CHESS_FILE_E,
    CHESS_FILE_F,
    CHESS_FILE_G,
    CHESS_FILE_H
} ChessFile;

typedef enum
{
    CHESS_RANK_INVALID = -1,
    CHESS_RANK_1 = 0,
    CHESS_RANK_2,
    CHESS_RANK_3,
    CHESS_RANK_4,
    CHESS_RANK_5,
    CHESS_RANK_6,
    CHESS_RANK_7,
    CHESS_RANK_8
} ChessRank;

typedef enum ChessSquare
{
    CHESS_SQUARE_INVALID = -1,
    CHESS_SQUARE_A1 = 0,
    CHESS_SQUARE_B1 = 1,
    CHESS_SQUARE_C1 = 2,
    CHESS_SQUARE_D1 = 3,
    CHESS_SQUARE_E1 = 4,
    CHESS_SQUARE_F1 = 5,
    CHESS_SQUARE_G1 = 6,
    CHESS_SQUARE_H1 = 7,
    CHESS_SQUARE_A2 = 8,
    CHESS_SQUARE_B2 = 9,
    CHESS_SQUARE_C2 = 10,
    CHESS_SQUARE_D2 = 11,
    CHESS_SQUARE_E2 = 12,
    CHESS_SQUARE_F2 = 13,
    CHESS_SQUARE_G2 = 14,
    CHESS_SQUARE_H2 = 15,
    CHESS_SQUARE_A3 = 16,
    CHESS_SQUARE_B3 = 17,
    CHESS_SQUARE_C3 = 18,
    CHESS_SQUARE_D3 = 19,
    CHESS_SQUARE_E3 = 20,
    CHESS_SQUARE_F3 = 21,
    CHESS_SQUARE_G3 = 22,
    CHESS_SQUARE_H3 = 23,
    CHESS_SQUARE_A4 = 24,
    CHESS_SQUARE_B4 = 25,
    CHESS_SQUARE_C4 = 26,
    CHESS_SQUARE_D4 = 27,
    CHESS_SQUARE_E4 = 28,
    CHESS_SQUARE_F4 = 29,
    CHESS_SQUARE_G4 = 30,
    CHESS_SQUARE_H4 = 31,
    CHESS_SQUARE_A5 = 32,
    CHESS_SQUARE_B5 = 33,
    CHESS_SQUARE_C5 = 34,
    CHESS_SQUARE_D5 = 35,
    CHESS_SQUARE_E5 = 36,
    CHESS_SQUARE_F5 = 37,
    CHESS_SQUARE_G5 = 38,
    CHESS_SQUARE_H5 = 39,
    CHESS_SQUARE_A6 = 40,
    CHESS_SQUARE_B6 = 41,
    CHESS_SQUARE_C6 = 42,
    CHESS_SQUARE_D6 = 43,
    CHESS_SQUARE_E6 = 44,
    CHESS_SQUARE_F6 = 45,
    CHESS_SQUARE_G6 = 46,
    CHESS_SQUARE_H6 = 47,
    CHESS_SQUARE_A7 = 48,
    CHESS_SQUARE_B7 = 49,
    CHESS_SQUARE_C7 = 50,
    CHESS_SQUARE_D7 = 51,
    CHESS_SQUARE_E7 = 52,
    CHESS_SQUARE_F7 = 53,
    CHESS_SQUARE_G7 = 54,
    CHESS_SQUARE_H7 = 55,
    CHESS_SQUARE_A8 = 56,
    CHESS_SQUARE_B8 = 57,
    CHESS_SQUARE_C8 = 58,
    CHESS_SQUARE_D8 = 59,
    CHESS_SQUARE_E8 = 60,
    CHESS_SQUARE_F8 = 61,
    CHESS_SQUARE_G8 = 62,
    CHESS_SQUARE_H8 = 63
} ChessSquare;

typedef enum
{
    CHESS_CASTLE_STATE_NONE = 0,
    CHESS_CASTLE_STATE_WK = (1 << 0),
    CHESS_CASTLE_STATE_WQ = (1 << 1),
    CHESS_CASTLE_STATE_BK = (1 << 2),
    CHESS_CASTLE_STATE_BQ = (1 << 3),
    CHESS_CASTLE_STATE_WKQ = CHESS_CASTLE_STATE_WK | CHESS_CASTLE_STATE_WQ,
    CHESS_CASTLE_STATE_BKQ = CHESS_CASTLE_STATE_BK | CHESS_CASTLE_STATE_BQ,
    CHESS_CASTLE_STATE_ALL = CHESS_CASTLE_STATE_WKQ | CHESS_CASTLE_STATE_BKQ
} ChessCastleState;

typedef enum
{
    CHESS_RESULT_NONE,
    CHESS_RESULT_WHITE_WINS,
    CHESS_RESULT_BLACK_WINS,
    CHESS_RESULT_DRAW,
    CHESS_RESULT_IN_PROGRESS
} ChessResult;

ChessColor chess_color_other(ChessColor color);

ChessColor chess_piece_color(ChessPiece piece);
ChessPiece chess_piece_of_color(ChessPiece piece, ChessColor color);
char chess_piece_to_char(ChessPiece piece);
ChessPiece chess_piece_from_char(char);

ChessSquare chess_square_from_fr(ChessFile, ChessRank);
ChessFile chess_square_file(ChessSquare);
ChessRank chess_square_rank(ChessSquare);

ChessFile chess_file_from_char(char);
ChessRank chess_rank_from_char(char);
char chess_file_to_char(ChessFile);
char chess_rank_to_char(ChessRank);

#endif /* CHESSLIB_CHESS_H_ */

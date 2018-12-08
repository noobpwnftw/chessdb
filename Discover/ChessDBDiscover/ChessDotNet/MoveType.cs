namespace ChessDotNet
{
    [System.Flags]
    public enum MoveType
    {
        Invalid = 1,
        Move = 2,
        Capture = 4,
        Castling = 8,
        Promotion = 16
    }
}

<?php

function cbgetfen(string $fen): array {}

function cbmovegen(string $fen, bool $frc = false): array {}

function cbmovemake(string $fen, string $move, bool $frc = false): array {}

function cbmovesan(string $fen, array $moves, bool $frc = false): array {}

function cbgetBWfen(string $fen): string {}

function cbgetBWmove(string $move): string {}

function cbincheck(string $fen, bool $frc = false): ?bool {}

function cbfen2hexfen(string $fen): string {}

function cbhexfen2fen(string $hexfen): string {}
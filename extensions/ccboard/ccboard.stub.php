<?php

function ccbgetfen(string $fen): ?string {}

function ccbmovegen(string $fen): array {}

function ccbmovemake(string $fen, string $move): ?string {}

function ccbgetLRfen(string $fen): ?string {}

function ccbgetBWfen(string $fen): ?string {}

function ccbgetLRBWfen(string $fen): ?string {}

function ccbgetLRmove(string $move): ?string {}

function ccbgetBWmove(string $move): ?string {}

function ccbgetLRBWmove(string $move): ?string {}

function ccbincheck(string $fen): ?bool {}

function ccbfen2hexfen(string $fen): ?string {}

function ccbhexfen2fen(string $hexfen): ?string {}

function ccbrulecheck(string $fen, array $arr, bool $verify = false, int $check_times = 1): ?int {}

function ccbruleischase(string $fen, string $move): ?int {}
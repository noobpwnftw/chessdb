<?php

function cbgetfen(string $fen): ?string {}

function cbmovegen(string $fen): array {}

function cbmovemake(string $fen, string $move): ?string {}

function cbmovesan(string $fen, array $moves): array {}

function cbgetBWfen(string $fen): string {}

function cbgetBWmove(string $move): string {}

function cbincheck(string $fen): ?bool {}

function cbfen2hexfen(string $fen): string {}

function cbhexfen2fen(string $hexfen): string {}
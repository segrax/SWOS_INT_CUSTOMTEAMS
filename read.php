<?php
function readByteFromBytes($data, $offset)
{
  return $data[$offset + 1];
}

function readWordFromBytes($data, $offset)
{
  $offset += 1;
  if (!isset($data[$offset]) || !isset($data[$offset + 1])) {
    throw new Exception("Invalid offset or array size is too small.");
  }
  return (((int)$data[$offset]) << 8) | (int)$data[$offset + 1];
}

function readDWordFromBytes($data, $offset)
{
  $offset += 1;
  if (!isset($data[$offset]) || !isset($data[$offset + 3])) {
    throw new Exception("Invalid offset or array size is too small.");
  }
  $dword = (((int)$data[$offset]) << 24) |
    (((int)$data[$offset + 1]) << 16) |
    (((int)$data[$offset + 2]) << 8) |
    (int)$data[$offset + 3];

  return $dword;
}

function Team_FindRecord(&$data, $pTeamDataOffset, $pMaxRecord, $pTeamNumber)
{

  while (true) {
    if ($pTeamNumber == 0) {
      return $pTeamDataOffset; // Equivalent to locret_9FEC2 (rts)
    }

    // Decrement the record count (d0)
    $pTeamNumber -= 1;

    // Add the word value at address a0 to a0 (move to the next record)
    $pTeamDataOffset += readWordFromBytes($data, $pTeamDataOffset);

    // Compare current record (a0) with target record (a4)
    if ($pTeamDataOffset == $pMaxRecord) {
      // Record found, return
      return $pTeamDataOffset; // Equivalent to Team_FindRecord (success case)
    }

    if ($pTeamDataOffset > $pMaxRecord) {
      return false;
    }
    // If the records are not equal, continue the loop
  }
}


function DecodeName($data, $pTeamStartOffset, $d0, &$a2): string
{
  $a0 = $pTeamStartOffset;
  $start = $d0;

  $d2 = $d0; // move.w d0, d2
  $d2 = $d2 >> 5; // lsr.w #5, d2
  $d0 = $d0 & 0x1F; // andi.w #$1F, d0
  $zFlag = ($d2 & (1 << 0)) == 0; // Z flag is set if bit 0 was already 0
  $d2 &= ~(1 << 0); // bclr #0, d2

  if (!$zFlag) { // beq.s loc_A019A
    $d0 += 8; // addq.w #8, d0
    $d0 &= ~(1 << 5); // bclr #5, d0

    if ($d0 != 0) { // beq.s loc_A019A
      $d2 += 2; // addq.w #2, d2
    }
  }

  // loc_A019A
  $a0 += $d2;

  $d2 = readDWordFromBytes($data, $a0); // move.l (a0), d2
  echo "Reading 0x" . dechex($a0) . " (rotate $d0)  ($start 0x" . dechex($start) . ") - (" . dechex($d2) . ")\n";
  $d2 = rol($d2, $d0); // rol.l d0, d2 (rotate left logical)
  $a1 = '';

  do {
    do {
      $d2 = rol($d2, 5); // rol.l #5, d2
      $d1 = $d2 & 0x1F; // andi.w #$1F, d1
      if (!$d1)
        break 2;

      $d1 = $a2[$d1]; // move.b (a2, d1.w), d1
      $a1 .= $d1; // move.b d1, (a1)+ (append to a1 array for emulation)

      $d0 += 5; // addq.w #5, d0

    } while ($d0 < 0x10); // cmp.w #$10, d0 / blt.s loc_A01A6

    $d0 -= 0x10; // subi.w #$10, d0
    $a0 += 2; // addq.l #2, a0
    $d2 = readDWordFromBytes($data, $a0); // move.l (a0), d2
    $d2 = rol($d2, $d0); // rol.l d0, d2
  } while (1);

  return $a1; // rts
}

function rol($value, $bits)
{
  $bits = $bits % 32;
  return (($value << $bits) | ($value >> (32 - $bits))) & 0xFFFFFFFF;
}

$byte_A01Ce = [null, 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', ' ', '-', "'", '.', null];

// NATS
$data = unpack("C*", file_get_contents("NATS"));
$teamStart = 0x5F0; // TEAMS HEADER
$endRecordOffset = readDWordFromBytes($data, $teamStart + 0x04);
$teamStart += 0x08; // Skip header
// End NATS

// CUSTOM
$data = unpack("C*", file_get_contents("new_teams_file.dat"));
//$data = unpack("C*", file_get_contents("CUST"));
$teamStart = 0;
$endRecordOffset = count($data);
// End Custom

for ($teamIndex = 0;; ++$teamIndex) {

  $teamRecord = Team_FindRecord($data, $teamStart, $teamStart + $endRecordOffset, $teamIndex);
  if ($teamRecord === false)
    break;

  $teamNameOffset = readWordFromBytes($data, $teamRecord + 0x02);
  $teamName = DecodeName($data, $teamRecord, $teamNameOffset, $byte_A01Ce);

  echo "$teamIndex: $teamName\n";

  $playerStartOffset = $teamRecord + 0x06;
  $playerDataLimit = $teamRecord + ($teamNameOffset >> 5);

  for (
    $playerIndex = 0, $playerOffset = $playerStartOffset;
    $playerOffset < $playerDataLimit;
    $playerIndex++, $playerOffset += 8
  ) {

    $playerRecord = readWordFromBytes($data, $playerOffset);
    $playerName = DecodeName($data, $teamRecord, $playerRecord, $byte_A01Ce);
    $playerAttributes = readByteFromBytes($data, $playerOffset + 2);
    $playerPositionData = readByteFromBytes($data, $playerOffset + 3);

    $playerNumber = ($playerAttributes & 0x0F) + 1;
    $positionCode = $playerPositionData & 0x0F;

    $position = match ($positionCode) {
      0x04 => 'D',
      0x08 => 'M',
      0x0C => 'F',
      default => 'G'
    };

    if ($playerPositionData & 0x10) {
      $position .= '*';
    }

    $formattedPlayerNumber = str_pad($playerNumber, 3, ' ', STR_PAD_LEFT);
    $formattedPlayerName = str_pad($playerName, 25);
    $formattedPosition = str_pad($position, 12);

    echo "  $formattedPlayerNumber: $formattedPlayerName - " . " $formattedPosition (unk: " . ($playerAttributes & 0xF0) . ")\n";
  }
}

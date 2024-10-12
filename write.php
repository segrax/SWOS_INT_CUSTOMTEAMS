<?php

function writeByteToBytes(&$data, $value)
{
    $data .= pack('C', $value);
}

function writeWordToBytes(&$data, $value)
{
    $data .= pack('n', $value); // 'n' for big-endian unsigned short (2 bytes)
}

function rol($value, $bits)
{
    $bits = $bits % 32;
    return (($value << $bits) | ($value >> (32 - $bits))) & 0xFFFFFFFF;
}

function encodeStrings($inputStrings, $table, $off) {
    $outputBytes = [];
    $metadata = [];
    $workingBuffer = 0;
    $bitCount = 0;
    $byteOffset = 0;
    $totalbitcount = 0;

    // Loop through each string in the input array
    foreach ($inputStrings as $stringIndex => $name) {
        // Record the starting byte offset and bit shift count for this string
        $metadata[] = [
            'string_index' => $stringIndex,
            'byte_offset' => $byteOffset,
            'bit_shift_count' => $bitCount
        ];

        echo "Writing $name: 0x" . dechex($off + $totalbitcount) . " ";
        $name .= "\0";

        foreach (str_split($name) as $char) {
            $index = array_search(strtoupper($char), $table);
            if ($index === false) {
                throw new Exception("Invalid character in input string.");
            }

            // Write 5-bit value to working buffer
            $workingBuffer |= $index;
            $bitCount += 5;
            $totalbitcount += 5;

            // If the working buffer has more than 16 bits, we extract 16 bits
            if ($bitCount >= 16) {
                // Extract 16 bits and save to output
                $outputBytes[] = ($workingBuffer >> ($bitCount - 16)) & 0xFFFF;

                // Rotate the buffer by reducing it to last remaining bits
                $bitCount -= 16;
                $workingBuffer &= (1 << $bitCount) - 1; // Keep the last remaining bits

                // Increment the byte offset since we output 16 bits (2 bytes)
                $byteOffset += 2;
            }

            $workingBuffer = rol($workingBuffer, 5);
        }
        echo "  End 0x" . dechex($off + $totalbitcount) . "\n";
    }

    // Handle any remaining bits in the working buffer after all strings
    if ($bitCount > 0) {
        $workingBuffer>>=5;
        $outputBytes[] = $workingBuffer << (16 - $bitCount);
        $byteOffset += 2;
    }

    return ['encoded' => $outputBytes, 'metadata' => $metadata];
}

function createTeamRecord($teamName, $players, $byte_A01Ce)
{
    $teamRecord = '';
    $namesData = '';

    // Calculate offsets for the start of each name within the encoded name stream
    $nameDataOffset = 6 + (count($players) * 8); // After the player data
    $names = array_merge([$teamName], array_column($players, 'name'));

    // Encode team name and all player names together in one stream
    $encodedNames = encodeStrings($names, $byte_A01Ce, $nameDataOffset);

    $teamNameOffset = ($nameDataOffset) << 5;

    // Write player data (name offset, attributes, position)
    foreach ($players as $index => $player) {
        $nameOffset = ($nameDataOffset + $encodedNames['metadata'][$index+1]['byte_offset']) << 5;
        $nameShift = $encodedNames['metadata'][$index+1]['bit_shift_count'];

        $playerAttributes = $player['attributes'];
        $playerPositionCode = $player['positionCode'];
        $position = match ($playerPositionCode) {
            'G' => 0x00,
            'D' => 0x04,
            'M' => 0x08,
            'F' => 0x0C,
            default => 0x00
        };
        if($player['star'] ?? false) {
            $position |= 0x10;
        }
        $playerAttributes |= $player['position'] & 0x0F;

        // Write player name offset
        writeWordToBytes($teamRecord, $nameOffset | ($nameShift & 0x1f));

        // Write player attributes and position
        writeByteToBytes($teamRecord, $playerAttributes);
        writeByteToBytes($teamRecord, $position);

        // Add blank bytes (Unknown Data)
        $teamRecord .= str_repeat("\x00", 4); // Add 2 null bytes for padding
    }

    // Write names
    foreach ($encodedNames['encoded'] as $dword) {
        writeWordToBytes($namesData, $dword);
    }

    // Calculate total record size
    $totalRecordSize = 6 + strlen($teamRecord) + strlen($namesData);

    $header = '';

    // Write the total record size at the beginning
    writeWordToBytes($header, $totalRecordSize);

    // Write the team name offset
    writeWordToBytes($header, $teamNameOffset);

    // Skip the next 2 bytes (write zero)
    writeWordToBytes($header, 0);

    return $header . $teamRecord . $namesData;
}

function writeTeamsToFile($filePath, $teamsData, $byte_A01Ce)
{
    $data = '';

    // Start writing team data
    foreach ($teamsData as $teamIndex => $team) {
        $teamRecord = createTeamRecord($team['name'], $team['players'], $byte_A01Ce);
        $data .= $teamRecord;
    }

    // Write binary data to file
    file_put_contents($filePath, $data);
}

// Sample teams data for testing
$teamsData = [
    [
        'name' => "OPEN FODDER",
        'players' => [
            ['name' => "codeRed",        'position' => 1, 'attributes' => 0x00, 'positionCode' => 'G', 'star' => false],
            ['name' => "GWV",            'position' => 3, 'attributes' => 0x00, 'positionCode' => 'G', 'star' => false],
            ['name' => "Ian",            'position' => 1, 'attributes' => 0x00,    'positionCode' => 'G', 'star' => false],
            ['name' => "Jesus",          'position' => 12,'attributes' => 0xF0, 'positionCode' => 'G', 'star' => false],
            ['name' => "mercutio",       'position' => 2, 'attributes' => 0x10, 'positionCode' => 'D', 'star' => false],
            ['name' => "OmniBlade",      'position' => 14,'attributes' => 0xF0, 'positionCode' => 'D', 'star' => false],
            ['name' => "PanMac",         'position' => 3, 'attributes' => 0x20, 'positionCode' => 'D', 'star' => false],
            ['name' => "Playaveli",      'position' => 5, 'attributes' => 0x40, 'positionCode' => 'D', 'star' => true],
            ['name' => "Redhair",        'position' => 13,'attributes' => 0xF0, 'positionCode' => 'D', 'star' => false],
            ['name' => "Rileye",         'position' => 6, 'attributes' => 0x50, 'positionCode' => 'M', 'star' => false],
            ['name' => "segra",          'position' => 7, 'attributes' => 0x60, 'positionCode' => 'G', 'star' => true],
            ['name' => "themadfitz",     'position' => 8, 'attributes' => 0x80, 'positionCode' => 'M', 'star' => false],
            ['name' => "TyroneSlothrop", 'position' => 9, 'attributes' => 0x70, 'positionCode' => 'M', 'star' => false],
            ['name' => "W Livi",         'position' => 4, 'attributes' => 0x30, 'positionCode' => 'M', 'star' => false],
            ['name' => "WinterMute",     'position' => 15,'attributes' => 0xF0, 'positionCode' => 'F', 'star' => false],
            ['name' => "ZaDarkSide",     'position' => 10,'attributes' => 0x90, 'positionCode' => 'G', 'star' => false],
            ['name' => "ztronzo",        'position' => 11,'attributes' => 0xA0, 'positionCode' => 'G', 'star' => false],
            ['name' => "AMIGA",          'position' => 16,'attributes' => 0xA0, 'positionCode' => 'F', 'star' => false],
            
        ],
    ],
    [
        'name' => "SENSIBLE XI",
        'players' => [
            ['name' => "SUITLAND",      'position' => 1,  'attributes' => 0x00, 'positionCode' => 'G', 'star' => false],
            ['name' => "XH",            'position' => 3,  'attributes' => 0x00, 'positionCode' => 'G', 'star' => false],
            ['name' => "CHRIS YATES",   'position' => 1,  'attributes' => 0x00, 'positionCode' => 'G', 'star' => false],
            ['name' => "GAVIN WADE",    'position' => 12, "attributes" => 0xF0, "positionCode" => 'G', 'star' => false],
            ["name" => "CHRIS CHAPMAN", 'position' => 2,  "attributes" => 0x10, "positionCode" => 'D', 'star' => false],
            ["name" => "SERGE VAN HOOF",'position' => 14,  "attributes" => 0xF0, "positionCode" => 'D', 'star' => false],
            ["name" => "STOO",          'position' => 3,   "attributes" => 0x20, "positionCode" => 'D', 'star' => false],
            ["name" => "RICHARD JOSEPH",'position' => 5,   "attributes" => 0x40, "positionCode" => 'D', 'star' => true],
            ["name" => "NIGEL THOMPSON",'position' => 13,  "attributes" => 0xF0, "positionCode" => 'D', 'star' => false],
            ["name" => "UBIK",          'position' => 6,   "attributes" => 0x50, "positionCode" => 'M', 'star' => false],
            ["name" => "MIKE HAMMOND",  'position' => 7,   "attributes" => 0x60, "positionCode" => 'G', 'star' => false],
            ["name" => "JOOLS",         'position' => 8,  "attributes" => 0x80, "positionCode" => 'M', 'star' => false],
            ["name" => "JOPS",          'position' => 9,  "attributes" => 0x70, "positionCode" => 'M', 'star' => false],
            ["name" => "NIGEL LAYTON",  'position' => 4,  "attributes" => 0x30, "positionCode" => 'M', 'star' => false],
            ["name" => "ALAN KUHNEL",   'position' => 15, "attributes" => 0xF0, "positionCode" => 'F', 'star' => false],
            ["name" => "MR BOOMFACE",   'position' => 10, "attributes" => 0x90, "positionCode" => 'G', 'star' => false],
            ["name" => "SCOTT WALSH",   'position' => 11, "attributes" => 0xA0, "positionCode" => 'G', 'star' => false],
            ["name" => "DARREN MILLS",  'position' => 16, "attributes" => 0xF0, "positionCode" => 'F', 'star' => false],
        ],
    ],
];

// Character encoding table (byte_A01Ce) array
$byte_A01Ce = ["\0", 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', ' ', '-', "'", '.', null];

// Write teams data to file
writeTeamsToFile('new_teams_file.dat', $teamsData, $byte_A01Ce);

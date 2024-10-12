# SWOS International Team reader/writer

Some hacked up scripts for reading and writing custom teams.

There is no parameters, edit the scripts to change settings.

## Read.php
At the start of the script, you’ll find two blocks of code:

- NATS: This is for reading from the larger NATS file, which contains league and other game-related details.
- CUST: This is for reading the CUST file, which focuses on team-specific data (excluding the TEAM header).

  Simplfy comment out the CUSTOM block to read from NATS
  
```php
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

```

## Write.php

TeamsData is hardcoded towards the end of the script, $teamsData.
player "attributes" are unknown data

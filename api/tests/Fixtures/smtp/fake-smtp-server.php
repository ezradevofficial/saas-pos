<?php

// SmtpProbeTest: a minimal SMTP server on 127.0.0.1 that accepts one
// password (argv[1]) through AUTH PLAIN or AUTH LOGIN. Prints the port it
// listens on, then serves connections until it is stopped. Nothing is sent
// anywhere: there is no DATA handling beyond a 250.

$password = $argv[1] ?? 'secret';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
echo 'PORT '.parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n";

while ($client = stream_socket_accept($server, -1)) {
    $say = fn (string $line) => fwrite($client, $line."\r\n");
    $read = fn () => rtrim((string) fgets($client), "\r\n");
    $say('220 fake ESMTP');
    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        $verb = strtoupper(strtok($line, ' '));
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $say('250-fake');
            $say('250 AUTH PLAIN LOGIN');
        } elseif ($verb === 'AUTH') {
            $parts = explode(' ', $line);
            if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                $given = explode("\0", (string) base64_decode($parts[2] ?? ''))[2] ?? '';
            } else {
                $say('334 VXNlcm5hbWU6');
                $read();
                $say('334 UGFzc3dvcmQ6');
                $given = (string) base64_decode($read());
            }
            $say($given === $password ? '235 2.7.0 Authentication successful' : '535 5.7.8 Authentication credentials invalid');
        } elseif ($verb === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('250 ok');
        }
    }
    fclose($client);
}

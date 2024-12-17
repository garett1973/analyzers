<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartBioMaximaServerCommand extends Command
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;
    const DC1 = HexCodes::DC1->value;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'biomaxima:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the BioMaxima Analyzer as server';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Biomaxima Analyzer Command');
        $messages = [
            self::STX . self::DC1 . self::CR . self::LF .
'ID:1210009192        ' . self::CR . self::LF .
'NO.006068  2024-10-29' . self::CR . self::LF .
'             15:54:27' . self::CR . self::LF .
'                     ' . self::CR . self::LF .
' LEU -       0CELL/uL' . self::CR . self::LF .
' KET -       0', ' mmol/L' . self::CR . self::LF .
' NIT -               ' . self::CR . self::LF .
' URO        Normal   ' . self::CR . self::LF .
'*BIL +2     33 umol/L' . self::CR . self::LF .
' GLU -       0 mmol/L' . self::CR . self::LF .
'*PR', 'O +-   0.15    g/L' . self::CR . self::LF .
' SG        1.015     ' . self::CR . self::LF .
' pH        6.5       ' . self::CR . self::LF .
' BLD -       0CELL/uL' . self::CR . self::LF .
' Vc  -       0 ', 'mmol/L' . self::CR . self::LF .
'*MA      >=150   mg/L' . self::CR . self::LF .
' Ca        5.0 mmol/L' . self::CR . self::LF .
'*CR     >=26.4 mmol/L' . self::CR . self::LF .
'*ACR  3.4~33.9mg/mmol' . self::CR . self::LF .
' C', 'olor:             ' . self::CR . self::LF .
' Clarity:            ' . self::CR . self::LF . self::ETX,
        ];

        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ipAddress = '192.168.0.111';
        $port = 11114;
        socket_bind($serverSocket, $ipAddress, $port);
        socket_listen($serverSocket);
        $this->info("Socket server started on {$ipAddress}:{$port}");

        while (true) {
            $clientSocket = @socket_accept($serverSocket);
            if ($clientSocket === false) {
                $this->info("Failed to accept connection");
                continue;
            }

            socket_getpeername($clientSocket, $ip);
            $this->info("Client IP: $ip");
            while (true) {
                if (socket_get_option($clientSocket, SOL_SOCKET, SO_ERROR) !== 0) {
                    $this->info("Client disconnected");
                    break;
                }

                foreach ($messages as $message) {
                    if (@socket_write($clientSocket, $message, strlen($message)) === false) {
                        $this->info("Socket write error: " . socket_strerror(socket_last_error($clientSocket)));
                        break;
                    }
                    $this->info("Sent: $message");
                    sleep(1);
                }
                break;
            }

            socket_close($clientSocket);
            break;
        }
    }
}

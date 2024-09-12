<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartDefaultAnalyzerCommand extends Command
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    protected $signature = 'default:start';
    protected $description = 'Start the default analyzer';

    private $results = [
        'R|1|^3218577797|^^^25-OH VD II|5.7|%|4.0-6.0|N||F|||20240507|', // Vitamino D koncentracijos nustatymas (25-hidroksivitaminas D)
        'R|1|^3218577797|^^^CA125|5.7|%|4.0-6.0|N||F|||20240507|', // Kiaušidžių vėžio žymuo CA 125
        'R|1|^3218577797|^^^TOXO-IgG|5.7|%|4.0-6.0|N||F|||20240507|', // Toxoplasma gondii IgG nustatymas imunofermentiniu metodu
        'R|1|^3218577797|^^^TOXO_IgM|5.7|%|4.0-6.0|N||F|||20240507|', // Toxoplasma gondii IgM nustatymas imunofermentiniu metodu
        'R|1|^3218577797|^^^CA72-4|5.7|%|4.0-6.0|N||F|||20240507|', // Skrandžio vėžio žymens Ca 72-4 nustatymas
        'R|1|^3218577797|^^^PCT|5.7|%|4.0-6.0|N||F|||20240507|', // Prokalcitoninas (PCT)
        'R|1|^3218577797|^^^BGP|5.7|%|4.0-6.0|N||F|||20240507|', // Osteokalcino nustatymas imunofermentiniu metodu
        'R|1|^3218577797|^^^NT-proBNP|5.7|%|4.0-6.0|N||F|||20240507|', // NT pro BNP (širdies nepakankamumo žymuo) nustatymas
        'R|1|^3218577797|^^^IgE|5.7|%|4.0-6.0|N||F|||20240507|', // Imunoglobulino E koncentracijos nustatymas
        'R|1|^3218577797|^^^Anti-HCV II|5.7|%|4.0-6.0|N||F|||20240507|', // Hepatito C viruso (HCV) kiekybinis antikūnų nustatymas
        'R|1|^3218577797|^^^Ferritin|5.7|%|4.0-6.0|N||F|||20240507|', // Feritino koncentracijos nustatymas
        'R|1|^3218577797|^^^HE4|5.7|%|4.0-6.0|N||F|||20240507|', // Epitelinis kiaušidžių vėžio žymuo HE4
        'R|1|^3218577797|^^^CMV_IgM|5.7|%|4.0-6.0|N||F|||20240507|', // Citomegalo viruso (CMV) IgM nustatymas
        'R|1|^3218577797|^^^CMV_IgG|5.7|%|4.0-6.0|N||F|||20240507|', // Citomegalo viruso (CMV) IgG nustatymas
    ];

    private bool $result_sent = false;
    private bool $order_request_sent = false;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ip = '127.0.0.1';
        $port = 1080;

        socket_bind($serverSocket, $ip, $port);
        socket_listen($serverSocket);

        $this->info("Socket server started on {$ip}:{$port}");

        $idle = true;
        $counter = 5;
        $clientSocket = socket_accept($serverSocket);
        $this->info("Client connected");
        socket_getpeername($clientSocket, $ip);
        $this->info("Client IP: $ip");

//        socket_set_option($clientSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 15, 'usec' => 0]);

        while (true) {
            while (socket_get_option($clientSocket, SOL_SOCKET, SO_ERROR) === 0) {
                if (env('DEFAULT_TEST')) {
                    echo socket_read($clientSocket, 1024);
                    continue;
                }

                while ($idle && $counter > 0) {
                    $this->sendENQ($clientSocket);
                    $ack = $this->recvACK($clientSocket);
                    usleep(500000);
                    $counter--;
                }

                $inc = $this->sendHeader($clientSocket);
                echo "Received response to header: $inc\n";
                if ($inc == self::ACK) {
                    $result_or_request = rand(0, 1);
                    if ($result_or_request) {
                        $inc = $this->sendResult($clientSocket);
                        echo "Received response to result: $inc\n";
                        if ($inc == self::ACK) {
                            $this->result_sent = true;
                        }
                    } else {
                        $inc = $this->sendOrderRequest($clientSocket);
                        echo "Received response to order request: $inc\n";
                        if ($inc == self::ACK) {
                            $this->order_request_sent = true;
                        }
                    }

                    if ($inc == self::ACK) {
                        $inc = $this->sendTerminator($clientSocket);
                        echo "Received response to terminator: $inc\n";
                        if ($inc == self::ACK) {
                            $inc = $this->sendETX($clientSocket);
                            if ($inc == self::ACK) {
                                echo "Received ACK\n";
                                $this->sendEOT($clientSocket);
                                $idle = true;
                                $counter = 5;
                                if ($this->result_sent) {
                                    $this->result_sent = false;
                                    continue;
                                }
                            }
                        }
                    }

                    $inc = socket_read($clientSocket, 1024);
                    if ($inc == self::ENQ) {
                        echo "Received ENQ - incoming order information\n";
                        $this->handleIncomingOrder($clientSocket);
                    }
                    $counter = 5;
                } else {
                    $idle = true;
                    $counter = 5;
                    $this->result_sent = false;
                    $this->order_request_sent = false;
                }
            }
            $this->info("Client disconnected");
            $clientSocket = socket_accept($serverSocket);
            $this->info("Client reconnected");
        }
    }

    private function sendENQ(false|\Socket $clientSocket): void {
        socket_write($clientSocket, self::ENQ, strlen(self::ENQ));
        echo "Sent ENQ\n";
    }

    private function sendHeader(false|\Socket $clientSocket): false|string {
        $header = $this->getLongHeader();
        echo "String header: " . $header . "\n";
        $header = bin2hex($header) . self::ETX;
        $checksum = $this->getChecksum($header);
        $header = self::STX . $header . $checksum . self::CR . self::LF;
        echo "Hex header: " . $header . "\n";
        socket_write($clientSocket, $header, strlen($header));
        return socket_read($clientSocket, 1024);
    }

    private function getLongHeader(): string
    {
        return 'H|\^&||PSWD|Maglumi User|||||Lis||P|E1394-97|' . date('Ymd');
    }

    private function getChecksum(string $msg): string {
        $checksum = 0;
        for ($i = 0; $i < strlen($msg); $i++) {
            $checksum += ord($msg[$i]);
        }
        $checksum = $checksum & 0xFF;
        return dechex($checksum);
    }

    private function sendResult(false|\Socket $clientSocket): false|string {
        $result = bin2hex($this->results[array_rand($this->results)]);
        echo "String result: " . hex2bin($result) . "\n";
        $result = self::STX . $result;
        $checksum = $this->getChecksum($result);
        $result = $result . $checksum . self::CR . self::LF;
        echo "Hex result: " . hex2bin($result) . "\n";
        socket_write($clientSocket, $result, strlen($result));
        $this->result_sent = true;
        return socket_read($clientSocket, 1024);
    }

    private function sendOrderRequest(false|\Socket $clientSocket): false|string {
        $orderRequest = bin2hex('Q|1|^3218577797||ALL||||||||O');
        $checksum = $this->getChecksum($orderRequest);
        echo "Sending order request: " . hex2bin($orderRequest) . "\n";
        $orderRequest = $orderRequest . $checksum;
        socket_write($clientSocket, $orderRequest, strlen($orderRequest));
        return socket_read($clientSocket, 1024);
    }

    private function sendTerminator(false|\Socket $clientSocket): false|string {
        $terminator = bin2hex('L|1|N');
        $checksum = $this->getChecksum($terminator);
        echo "Sending terminator: " . hex2bin($terminator) . "\n";
        $terminator = $terminator . $checksum;
        socket_write($clientSocket, $terminator, strlen($terminator));
        return socket_read($clientSocket, 1024);
    }

    private function sendETX(false|\Socket $clientSocket): false|string {
        socket_write($clientSocket, HexCodes::ETX->value, strlen(HexCodes::ETX->value));
        echo "Sent ETX\n";
        return socket_read($clientSocket, 1024);
    }

    private function sendEOT(false|\Socket $clientSocket): void {
        socket_write($clientSocket, HexCodes::EOT->value, strlen(HexCodes::EOT->value));
        echo "Sent EOT\n";
    }

    private function handleIncomingOrder(false|\Socket $clientSocket): void {
        $this->info("Handling incoming order");
        sleep(1);
        $this->sendACK($clientSocket);
        $inc = socket_read($clientSocket, 1024);
        if ($inc == self::EOT) {
            sleep(1);
            echo "Order was not found, received EOT\n";
            $this->sendACK($clientSocket);
            return;
        }
//        $inc = socket_read($clientSocket, 1024);
        if ($inc == self::STX) {
            echo "Received STX: $inc\n";
        } else {
            sleep(1);
            $this->sendNAK($clientSocket);
            return;
        }
        // reading short header -------------------------------------------------------------
        $inc = socket_read($clientSocket, 1024);
        if ($inc) {
            $checksum = $this->checkChecksum($inc);
            $inc = substr($inc, 0, -2);
            $inc = hex2bin($inc);
            echo "Received short header: $inc\n";
            // reading patient data -------------------------------------------------------------
            if ($checksum) {
                $inc = socket_read($clientSocket, 1024);
                if ($inc) {
                    $checksum = $this->checkChecksum($inc);
                    $inc = substr($inc, 0, -2);
                    $inc = hex2bin($inc);
                    echo "Received patient data: $inc\n";
                    // reading order data -------------------------------------------------------------
                    if ($checksum) {
                        $inc = socket_read($clientSocket, 1024);
                        if ($inc) {
                            $checksum = $this->checkChecksum($inc);
                            $inc = substr($inc, 0, -2);
                            $inc = hex2bin($inc);
                            echo "Received order data: $inc\n";
                            // reading terminator -------------------------------------------------------------
                            if ($checksum) {
                                $inc = socket_read($clientSocket, 1024);
                                if ($inc) {
                                    $checksum = $this->checkChecksum($inc);
                                    $inc = substr($inc, 0, -2);
                                    $inc = hex2bin($inc);
                                    echo "Received terminator: $inc\n";
                                    if ($checksum) {
                                        // reading ETX -------------------------------------------------------------
                                        $inc = socket_read($clientSocket, 1024);
                                        if ($inc == self::ETX) {
                                            echo "Received ETX: $inc\n";
                                        } else {
                                            sleep(1);
                                            $this->sendNAK($clientSocket);
                                            return;
                                        }
                                        // reading EOT -------------------------------------------------------------
                                        $inc = socket_read($clientSocket, 1024);
                                        if ($inc == self::EOT) {
                                            echo "Received EOT: $inc\n";
                                            sleep(1);
                                            $this->sendACK($clientSocket);
                                        }
                                    } else {
                                        sleep(1);
                                        $this->sendNAK($clientSocket);
                                    }
                                } else {
                                    sleep(1);
                                    $this->sendNAK($clientSocket);
                                    return;
                                }
                            } else {
                                sleep(1);
                                $this->sendNAK($clientSocket);
                            }
                        } else {
                            sleep(1);
                            $this->sendNAK($clientSocket);
                        }
                    } else {
                        sleep(1);
                        $this->sendNAK($clientSocket);
                    }
                } else {
                    sleep(1);
                    $this->sendNAK($clientSocket);
                }
            } else {
                sleep(1);
                $this->sendNAK($clientSocket);
            }
        }
    }

    private function sendACK( false|\Socket $clientSocket ): void {
        socket_write($clientSocket, self::ACK, strlen(self::ACK));
        echo "Sent ACK\n";
    }

    private function sendNAK(false|\Socket $clientSocket): void {
        socket_write($clientSocket, HexCodes::NAK->value, strlen(HexCodes::NAK->value));
        echo "Sent NAK\n";
    }

    private function checkChecksum(false|string $inc ): bool {
        $checksum = substr($inc, -2);
        $inc = substr($inc, 0, -2);
        $checksumCalc = 0;
        for ($i = 0; $i < strlen($inc); $i++) {
            $checksumCalc += ord($inc[$i]);
        }
        $checksumCalc = $checksumCalc & 0xFF;
        $checksumCalc = dechex($checksumCalc);
        return $checksum == $checksumCalc;
    }

    private function checkIfTimeout( $clientSocket ): bool {
        $errorCode = socket_last_error($clientSocket);
        if ($errorCode == SOCKET_ETIMEDOUT) {
            echo "Timeout occurred\n";
            return true;
        }
        return false;
    }

    private function sendSTX(false|\Socket $clientSocket): void {
        socket_write($clientSocket, HexCodes::STX->value, strlen(HexCodes::STX->value));
        echo "Sent STX\n";
    }

    private function sendComment(false|\Socket $clientSocket): false|string {
        $comment = bin2hex('C|1|^3218577797|Comment|');
        $checksum = $this->getChecksum($comment);
        echo "Sending comment: " . hex2bin($comment) . "\n";
        $comment = $comment . $checksum;
        socket_write($clientSocket, $comment, strlen($comment));
        return socket_read($clientSocket, 1024);
    }

    private function waitForData($socket, $timeout = 1): false|string|null {
        if (!is_resource($socket)) {
            echo "Error: Invalid socket resource\n";
            return false;
        }
        $read = [$socket];
        $write = null;
        $except = null;

        $numChangedStreams = stream_select($read, $write, $except, $timeout);

        if ($numChangedStreams === false) {
            echo "Error: stream_select failed\n";
            return false;
        } elseif ($numChangedStreams > 0) {
            // Data is available to read
            $data = fread($socket, 1024); // Adjust the buffer size as needed
            echo "Received data: $data\n";
            return $data;
        } else {
            // Timeout occurred, no data available
            echo "No data received within $timeout second(s)\n";
            return null;
        }
    }

    private function recvACK(false|\Socket $clientSocket): bool
    {
        $ack = socket_read($clientSocket, 1024);
        if ($ack == self::ACK) {
            echo "Received ACK\n";
            return true;
        }
        echo "Received NAK\n";
        return false;
    }
}

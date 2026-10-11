<?php

namespace App\Sender\Client;

use App\Sender\Contracts\FeedbackMailbox;
use RuntimeException;

/**
 * Минимальный IMAP-клиент поверх TLS-сокета: расширение imap в PHP 8.4 больше не входит в ядро.
 * Умеет войти, выбрать папку, найти непрочитанные письма, забрать их целиком и отметить прочитанными.
 */
class ImapFeedbackMailbox implements FeedbackMailbox
{
    /** @var resource|null */
    private $socket = null;

    private int $tag = 0;

    public function unseen(int $limit): array
    {
        $this->connect();

        $search = $this->command('UID SEARCH UNSEEN');
        $uids = preg_match('~^\* SEARCH([\d ]*)~m', $search, $m) ? array_map('intval', array_filter(explode(' ', trim($m[1])))) : [];
        $messages = [];

        foreach (array_slice($uids, 0, $limit) as $uid) {
            $response = $this->command("UID FETCH {$uid} BODY.PEEK[]");

            if (preg_match('~BODY\[\] \{(\d+)\}\r\n~', $response, $m, PREG_OFFSET_CAPTURE)) {
                $messages[$uid] = substr($response, $m[0][1] + strlen($m[0][0]), (int) $m[1][0]);
            }
        }

        return $messages;
    }

    public function markSeen(int $uid): void
    {
        $this->command("UID STORE {$uid} +FLAGS (\\Seen)");
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fwrite($this->socket, 'Z LOGOUT'."\r\n");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $host = (string) config('sender.fbl.host');
        $port = (int) config('sender.fbl.port', 993);
        $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, 20);

        if ($socket === false) {
            throw new RuntimeException("Нет соединения с {$host}:{$port}: {$error}");
        }

        stream_set_timeout($socket, 30);
        $this->socket = $socket;
        fgets($socket);

        $this->command(sprintf('LOGIN %s %s', $this->quote((string) config('sender.fbl.username')), $this->quote((string) config('sender.fbl.password'))));
        $this->command('SELECT '.$this->quote((string) config('sender.fbl.folder', 'INBOX')));
    }

    /**
     * Отправляет команду и читает ответ до строки с её тегом, вместе с литералами {N}.
     */
    private function command(string $command): string
    {
        $tag = 'A'.(++$this->tag);
        fwrite($this->socket, "{$tag} {$command}\r\n");
        $response = '';

        while (($line = fgets($this->socket)) !== false) {
            if (preg_match('~\{(\d+)\}\r\n$~', $line, $m)) {
                $line .= $this->readBytes((int) $m[1]);
                $response .= $line;

                continue;
            }

            if (str_starts_with($line, $tag.' ')) {
                if (! str_starts_with($line, $tag.' OK')) {
                    throw new RuntimeException('IMAP: '.trim(substr($line, strlen($tag) + 1)));
                }

                return $response;
            }

            $response .= $line;
        }

        throw new RuntimeException('IMAP: сервер закрыл соединение');
    }

    private function readBytes(int $length): string
    {
        $data = '';

        while (strlen($data) < $length && ! feof($this->socket)) {
            $data .= (string) fread($this->socket, $length - strlen($data));
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}

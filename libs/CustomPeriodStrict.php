<?php

declare(strict_types=1);

/**
 * Eigener Zeitraum aus der Kachel: {"from": "JJJJ-MM-TT", "to": "JJJJ-MM-TT"}, beide Tage eingeschlossen. Gebraucht vom
 * Verbrauchsbericht und vom Verbrauchsfluss. Typisiert für IPSModuleStrict.
 */
trait CustomPeriod
{
    /**
     * Prüft den Zeitraum und gibt Beginn und Ende (ausschließlich) als Zeitstempel zurück, sonst null. Der Zeitraum darf
     * nicht in der Zukunft liegen und höchstens zehn Jahre lang sein.
     *
     * @return array{start: int, end: int}|null
     */
    private function parseCustom(string $Json): ?array
    {
        $Data = json_decode($Json, true);
        if (!is_array($Data) || !is_string($Data['from'] ?? null) || !is_string($Data['to'] ?? null)) {
            return null;
        }
        $From = DateTimeImmutable::createFromFormat('!Y-m-d', $Data['from']);
        $To = DateTimeImmutable::createFromFormat('!Y-m-d', $Data['to']);
        if ($From === false || $To === false || $From->format('Y-m-d') !== $Data['from'] || $To->format('Y-m-d') !== $Data['to']) {
            return null;
        }
        $End = $To->modify('+1 day');
        if ($From >= $End || $To > new DateTimeImmutable('today') || $End->getTimestamp() - $From->getTimestamp() > 3660 * 86400) {
            return null;
        }
        return ['start' => $From->getTimestamp(), 'end' => $End->getTimestamp()];
    }
}

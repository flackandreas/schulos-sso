<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Das Entdeckungsdokument des Anbieters (/.well-known/openid-configuration).
 *
 * Wird zwischengespeichert, weil sonst jede Anmeldung zwei zusaetzliche
 * HTTP-Abrufe kostet - einen fuer die Weiterleitung und einen fuer den
 * Rueckweg.
 */
final class Entdeckung
{
    private const CACHE_SEKUNDEN = 3600;

    /** @var array<string,mixed>|null */
    private ?array $dokument = null;

    public function __construct(
        private readonly HttpAbruf $http,
        private readonly string $aussteller,
        private readonly string $cacheVerzeichnis = '',
    ) {
    }

    public function endpunkt(string $name): string
    {
        $adresse = (string) ($this->dokument()[$name] ?? '');
        if ($adresse === '') {
            throw new SsoFehler('Im Entdeckungsdokument fehlt: ' . $name);
        }

        return $adresse;
    }

    public function endpunktOderNull(string $name): ?string
    {
        $adresse = (string) ($this->dokument()[$name] ?? '');

        return $adresse !== '' ? $adresse : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function dokument(): array
    {
        if ($this->dokument !== null) {
            return $this->dokument;
        }

        $datei = $this->cacheDatei();
        if ($datei !== null && is_file($datei) && time() - (int) @filemtime($datei) <= self::CACHE_SEKUNDEN) {
            $inhalt = json_decode((string) @file_get_contents($datei), true);
            if (is_array($inhalt)) {
                return $this->dokument = $inhalt;
            }
        }

        $dokument = $this->http->holeJson(rtrim($this->aussteller, '/') . '/.well-known/openid-configuration');

        // Der Aussteller im Dokument muss zu dem passen, den wir gefragt haben -
        // sonst haette eine umgeleitete Adresse einen fremden Anbieter
        // untergeschoben.
        if (rtrim((string) ($dokument['issuer'] ?? ''), '/') !== rtrim($this->aussteller, '/')) {
            throw new SsoFehler('Das Entdeckungsdokument nennt einen anderen Aussteller als angefragt.');
        }

        if ($datei !== null) {
            $verzeichnis = dirname($datei);
            if (!is_dir($verzeichnis)) {
                @mkdir($verzeichnis, 0o750, true);
            }
            $vorlaeufig = $datei . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($vorlaeufig, json_encode($dokument, JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($vorlaeufig, $datei);
            } else {
                @unlink($vorlaeufig);
            }
        }

        return $this->dokument = $dokument;
    }

    private function cacheDatei(): ?string
    {
        if ($this->cacheVerzeichnis === '') {
            return null;
        }

        return rtrim($this->cacheVerzeichnis, '/') . '/oidc-' . sha1($this->aussteller) . '.json';
    }
}

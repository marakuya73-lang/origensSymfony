<?php

declare(strict_types=1);

namespace App\Artefact;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Tradição simbólica e glossário da oficina, lidos de data/symbolism.yaml.
 */
final class SymbolicTradition
{
    private const LABELS = [
        'stones' => 'pedra',
        'feathers' => 'pena',
        'materials' => 'material',
        'colours' => 'cor',
        'elements' => 'elemento',
        'chakras' => 'chakra',
        'signs' => 'signo',
        'planets' => 'planeta',
        'intentions' => 'intenção',
    ];

    /** Frases curtas destas listas viajam mesmo sem o nome no texto, para o áudio e a foto. */
    private const REMINDER_KINDS = ['stones', 'feathers', 'materials', 'colours', 'elements', 'intentions'];

    /** @var list<array{id: string, kind: string, label: string, title: string, names: list<string>, brief: string, text: string, related: list<string>}> */
    private array $entries = [];

    /** @var array<string, array{id: string, kind: string, label: string, title: string, names: list<string>, brief: string, text: string, related: list<string>}> */
    private array $byId = [];

    /** @var list<array{term: string, text: string, mishearings: list<string>}> */
    private array $glossary = [];

    private bool $loaded = false;

    public function __construct(
        #[Autowire('%kernel.project_dir%/data/symbolism.yaml')]
        private string $path,
    ) {
    }

    public static function open(): self
    {
        return new self(dirname(__DIR__, 2).'/data/symbolism.yaml');
    }

    /**
     * Entradas cujo nome aparece no texto, da mais específica para a mais curta.
     *
     * @return list<array{id: string, kind: string, label: string, title: string, names: list<string>, brief: string, text: string, related: list<string>}>
     */
    public function select(string $haystack): array
    {
        $this->load();
        $folded = $this->fold($haystack);
        $spans = [];
        foreach ($this->entries as $entry) {
            foreach ($entry['names'] as $name) {
                $needle = $this->fold($name);
                if ($needle === '') {
                    continue;
                }
                $token = ' '.$needle.' ';
                $padded = ' '.$folded.' ';
                $offset = 0;
                while (($pos = strpos($padded, $token, $offset)) !== false) {
                    $spans[] = [
                        'start' => $pos,
                        'end' => $pos + strlen($needle),
                        'len' => strlen($needle),
                        'id' => $entry['id'],
                    ];
                    $offset = $pos + 1;
                }
            }
        }

        usort($spans, static fn (array $a, array $b): int => $b['len'] <=> $a['len'] ?: $a['start'] <=> $b['start']);
        $kept = [];
        $ids = [];
        foreach ($spans as $span) {
            foreach ($kept as $other) {
                if ($span['start'] >= $other['start'] && $span['end'] <= $other['end'] && $span['len'] < $other['len']) {
                    continue 2;
                }
            }
            $kept[] = $span;
            $ids[$span['id']] = true;
        }

        $selected = [];
        foreach ($this->entries as $entry) {
            if (isset($ids[$entry['id']])) {
                $selected[] = $entry;
            }
        }

        return $selected;
    }

    public function promptFor(string $haystack): string
    {
        $this->load();
        $selected = $this->select($haystack);
        $lines = [
            'Glossário da oficina. Escreva estas palavras exatamente assim. Se o áudio soar parecido, use a grafia do glossário:',
        ];
        foreach ($this->glossary as $term) {
            $lines[] = '- '.$term['term'].': '.$term['text'];
        }
        $lines[] = 'Tradição para completar a fala dela. A fala dela prevalece: não a contradiga, não a resuma e não apague o que ela contou. Apresente o que segue como tradição, nunca como fato nem como ensinamento de um povo que ela não nomeou. Não invente um sentido que não esteja escrito aqui. Na nota de cada material, comece pelo que ela disse e complete com o sentido daquele material.';

        $shown = [];
        if ($selected !== []) {
            $lines[] = 'Entradas desta peça, porque estes nomes já aparecem nas notas, no formulário ou na conversa:';
            foreach ($selected as $entry) {
                $lines[] = '- '.$entry['title'].' ('.$entry['label'].'): '.$entry['text'];
                $shown[$entry['id']] = true;
            }
            $relatedLines = [];
            foreach ($selected as $entry) {
                foreach ($entry['related'] as $id) {
                    if (isset($shown[$id]) || !isset($this->byId[$id])) {
                        continue;
                    }
                    $related = $this->byId[$id];
                    $relatedLines[] = '- '.$related['title'].' ('.$related['label'].'): '.$related['brief'];
                    $shown[$id] = true;
                }
            }
            if ($relatedLines !== []) {
                $lines[] = 'Ligações destas entradas, só em uma frase:';
                array_push($lines, ...$relatedLines);
            }
        }

        $lines[] = 'Se o áudio ou a fotografia trouxer outro nome, use apenas a frase correspondente e não desenvolva além dela:';
        foreach ($this->entries as $entry) {
            if (!in_array($entry['kind'], self::REMINDER_KINDS, true) || isset($shown[$entry['id']])) {
                continue;
            }
            $lines[] = '- '.$entry['title'].' ('.$entry['label'].'): '.$entry['brief'];
        }

        return implode("\n", $lines);
    }

    public function correct(string $text): string
    {
        $this->load();
        $hearings = [];
        foreach ($this->glossary as $item) {
            foreach ($item['mishearings'] as $hearing) {
                if ($hearing !== '') {
                    $hearings[] = [$hearing, $item['term']];
                }
            }
        }
        usort($hearings, static fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        foreach ($hearings as [$hearing, $term]) {
            $text = preg_replace_callback(
                '/(?<![\p{L}\p{M}])'.preg_quote($hearing, '/').'(?![\p{L}\p{M}])/iu',
                fn (array $match): string => $this->matchCase($match[0], $term),
                $text,
            ) ?? $text;
        }

        return preg_replace_callback(
            '/[\p{L}\p{M}]+(?:[\'’-][\p{L}\p{M}]+)*/u',
            fn (array $match): string => $this->closeTerm($match[0]),
            $text,
        ) ?? $text;
    }

    /** @param array<mixed> $payload
     *  @return array<mixed>
     */
    public function correctPayload(array $payload): array
    {
        foreach (['title', 'description', 'dimensions', 'handmade'] as $field) {
            if (is_string($payload[$field] ?? null)) {
                $payload[$field] = $this->correct($payload[$field]);
            }
        }
        if (is_array($payload['materials'] ?? null)) {
            foreach ($payload['materials'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (['name', 'note'] as $key) {
                    if (is_string($row[$key] ?? null)) {
                        $row[$key] = $this->correct($row[$key]);
                    }
                }
                $payload['materials'][$index] = $row;
            }
        }
        foreach (['care', 'questions'] as $field) {
            if (!is_array($payload[$field] ?? null)) {
                continue;
            }
            foreach ($payload[$field] as $index => $line) {
                if (is_string($line)) {
                    $payload[$field][$index] = $this->correct($line);
                }
            }
        }

        return $payload;
    }

    private function closeTerm(string $word): string
    {
        $folded = $this->fold($word);
        foreach ($this->glossary as $item) {
            if ($folded === $this->fold($item['term'])) {
                return $word;
            }
        }

        $best = null;
        $bestDistance = 3;
        foreach ($this->glossary as $item) {
            $candidate = $this->fold($item['term']);
            if (strlen($candidate) < 7 || abs(strlen($folded) - strlen($candidate)) > 2) {
                continue;
            }
            if (!str_starts_with($folded, substr($candidate, 0, 4))) {
                continue;
            }
            $distance = levenshtein($folded, $candidate);
            if ($distance < 1 || $distance > 2 || $distance >= $bestDistance) {
                continue;
            }
            if ($distance === 2 && strlen($candidate) < 8) {
                continue;
            }
            $bestDistance = $distance;
            $best = $item['term'];
        }

        return $best === null ? $word : $this->matchCase($word, $best);
    }

    private function matchCase(string $source, string $term): string
    {
        if (mb_strtoupper($source, 'UTF-8') === $source) {
            return mb_strtoupper($term, 'UTF-8');
        }
        $first = mb_substr($source, 0, 1, 'UTF-8');
        if ($first !== '' && mb_strtoupper($first, 'UTF-8') === $first) {
            return mb_strtoupper(mb_substr($term, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($term, 1, null, 'UTF-8');
        }

        return $term;
    }

    private function fold(string $value): string
    {
        $folded = (new UnicodeString($value))->folded()->toString();
        $folded = str_replace(["'", '’', '-'], ' ', $folded);
        $folded = preg_replace('/[^a-z0-9]+/', ' ', $folded) ?? '';

        return trim(preg_replace('/\s+/', ' ', $folded) ?? '');
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        try {
            $parsed = Yaml::parseFile($this->path);
        } catch (ParseException) {
            throw new ProfileGenerationFailed('O acervo simbólico não pôde ser lido. Confira data/symbolism.yaml.', 500);
        }
        if (!is_array($parsed)) {
            throw new ProfileGenerationFailed('O acervo simbólico não pôde ser lido. Confira data/symbolism.yaml.', 500);
        }

        foreach (self::LABELS as $kind => $label) {
            foreach ($parsed[$kind] ?? [] as $row) {
                if (!is_array($row) || !is_string($row['id'] ?? null) || !is_array($row['names'] ?? null) || !is_string($row['brief'] ?? null) || !is_string($row['text'] ?? null)) {
                    continue;
                }
                $names = [];
                foreach ($row['names'] as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $names[] = trim($name);
                    }
                }
                if ($names === [] || isset($this->byId[$row['id']])) {
                    continue;
                }
                $related = [];
                foreach ($row['related'] ?? [] as $id) {
                    if (is_string($id) && $id !== '') {
                        $related[] = $id;
                    }
                }
                $entry = [
                    'id' => $row['id'],
                    'kind' => $kind,
                    'label' => $label,
                    'title' => $names[0],
                    'names' => $names,
                    'brief' => trim($row['brief']),
                    'text' => trim($row['text']),
                    'related' => $related,
                ];
                $this->entries[] = $entry;
                $this->byId[$entry['id']] = $entry;
            }
        }

        foreach ($parsed['glossary'] ?? [] as $row) {
            if (!is_array($row) || !is_string($row['term'] ?? null) || trim($row['term']) === '' || !is_string($row['text'] ?? null)) {
                continue;
            }
            $hearings = [];
            foreach ($row['mishearings'] ?? [] as $hearing) {
                if (is_string($hearing) && trim($hearing) !== '') {
                    $hearings[] = trim($hearing);
                }
            }
            $this->glossary[] = [
                'term' => trim($row['term']),
                'text' => trim($row['text']),
                'mishearings' => $hearings,
            ];
        }
    }
}

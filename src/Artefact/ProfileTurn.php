<?php

declare(strict_types=1);

namespace App\Artefact;

final readonly class ProfileTurn
{
    /** @var list<string> */
    public const FIELDS = ['title', 'description', 'dimensions', 'materials', 'care', 'handmade', 'size'];

    /**
     * @param list<string> $questions
     * @param list<string> $changed
     */
    public function __construct(
        public GeneratedProfile $profile,
        public array $questions,
        public bool $complete,
        public array $changed,
    ) {
    }

    /** @param array<mixed> $payload */
    public static function fromModel(array $payload): self
    {
        $complete = ($payload['complete'] ?? false) === true;

        return new self(
            GeneratedProfile::fromModel($payload),
            $complete ? [] : self::questions($payload['questions'] ?? []),
            $complete,
            self::changed($payload['changed'] ?? []),
        );
    }

    /** @return list<string> */
    private static function questions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $questions = [];
        foreach ($value as $question) {
            if (!is_string($question)) {
                continue;
            }
            $question = trim(preg_replace('/[^\P{C}\n\t]/u', '', $question) ?? '');
            if ($question === '') {
                continue;
            }
            if (mb_strlen($question) > 240) {
                $question = rtrim(mb_substr($question, 0, 240));
            }
            $questions[] = $question;
            if (count($questions) === 3) {
                break;
            }
        }

        return $questions;
    }

    /** @return list<string> */
    private static function changed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $changed = [];
        foreach ($value as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = self::ALIASES[mb_strtolower(trim($field))] ?? trim($field);
            if (!in_array($field, self::FIELDS, true) || in_array($field, $changed, true)) {
                continue;
            }
            $changed[] = $field;
        }

        return $changed;
    }

    /** @var array<string, string> */
    private const ALIASES = [
        'nome' => 'title',
        'titulo' => 'title',
        'título' => 'title',
        'descricao' => 'description',
        'descrição' => 'description',
        'dimensao' => 'dimensions',
        'dimensão' => 'dimensions',
        'material' => 'materials',
        'materiais' => 'materials',
        'cuidado' => 'care',
        'cuidados' => 'care',
        'feito à mão' => 'handmade',
        'feito a mao' => 'handmade',
        'tamanho' => 'size',
    ];
}

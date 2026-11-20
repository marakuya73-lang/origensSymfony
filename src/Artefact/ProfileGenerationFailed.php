<?php

declare(strict_types=1);

namespace App\Artefact;

final class ProfileGenerationFailed extends \RuntimeException
{
    public const UNCONFIGURED = 'A geração com IA não está configurada. Defina GEMINI_API_KEY no servidor.';

    public const UNAVAILABLE = 'Não foi possível gerar o perfil agora. Tente novamente em instantes.';

    public const LIMITED = 'O limite de uso da IA foi atingido. Aguarde um pouco e tente novamente.';

    public const REJECTED_KEY = 'A chave GEMINI_API_KEY foi recusada. Confira o valor no servidor.';

    public const UNUSABLE = 'A IA não devolveu um perfil utilizável. Ajuste a foto, o áudio ou as notas e tente de novo.';

    public const INVALID_MODEL = 'O modelo GEMINI_MODEL configurado não é válido.';

    public function __construct(string $message, private readonly int $status, private readonly string $detail = '')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function detail(): string
    {
        return $this->detail !== '' ? $this->detail : $this->getMessage();
    }
}

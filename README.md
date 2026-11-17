# ORIGENS

Arquivo de peças feitas à mão. Cada artefacto publicado fica em `/p/{token}`. O registro, a edição e a exportação ficam em `/admin/records`.

## Gerar o perfil com IA

No formulário de criar ou editar, a foto principal, um áudio opcional e notas opcionais podem sugerir o texto da peça. A sugestão só preenche o formulário. A peça não é gravada nesse passo: o botão de salvar grava, e o salvamento automático só volta a agir depois que você editar um campo.

1. Crie uma chave no plano gratuito do [Google AI Studio](https://aistudio.google.com/apikey).
2. Coloque a chave em `.env.local` (esse arquivo não entra no git):

```
GEMINI_API_KEY=sua-chave
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FALLBACK_MODEL=gemini-3.5-flash-lite
```

`GEMINI_API_KEY` fica vazio em `.env` de propósito. Não grave uma chave real em arquivo versionado. `GEMINI_MODEL` é opcional; o padrão é `gemini-3.8-flash`, um modelo Flash multimodal do plano gratuito. Se ele responder 503 UNAVAILABLE ou 429, o servidor tenta uma vez `GEMINI_FALLBACK_MODEL` (padrão `gemini-3.5-flash-lite`, também gratuito) antes de mostrar o erro. A chamada sai do servidor Symfony, então a chave não chega ao navegador.

No formulário, adicione pelo menos uma foto. Grave uma descrição curta no navegador (o microfone pede HTTPS) ou envie um arquivo WebM, MP3, M4A, WAV ou OGG de até 10 MB. As notas são livres e também opcionais. **Gerar perfil com IA** preenche nome, descrição, dimensão, materiais, cuidados, feito à mão e, numa peça nova, o tamanho. Revise e só então salve.

A categoria (1, 2 ou 3) continua manual: ela só distingue a referência da peça e não tem um nome no arquivo. Não há campo de preço.

Se a chave faltar, a cota da API acabar ou o serviço falhar, o formulário mostra o motivo em português e continua editável.

## Exportar para a planilha

Na lista de artefactos, marque as peças e use **Exportar CSV** ou **Exportar XLSX**. O CSV é UTF-8 com BOM e separador `;`, para o Excel em português abrir os acentos. A planilha traz referência, nome, descrição, tamanho, categoria, dimensão, materiais, cuidados, feito à mão, data do registro, endereço público e a URL da foto principal.

# ORIGENS

Arquivo de peças feitas à mão. Cada artefacto publicado fica em `/p/{token}`. O registro, a edição e a exportação ficam em `/admin/records`.

## Gerar o perfil com IA

No formulário de criar ou editar, a foto principal, um áudio opcional e notas opcionais começam o perfil. A sugestão preenche o que der para saber e, se faltar algo, faz perguntas curtas em português. Depois disso, nada é gravado sozinho, nem na conversa nem ao editar um campo: o botão de salvar é que grava.

1. Crie uma chave no plano gratuito do [Google AI Studio](https://aistudio.google.com/apikey).
2. Coloque a chave em `.env.local` (esse arquivo não entra no git):

```
GEMINI_API_KEY=sua-chave
GEMINI_MODEL=gemini-3.8-flash
GEMINI_FALLBACK_MODEL=gemini-3.5-flash-lite
```

`GEMINI_API_KEY` fica vazio em `.env` de propósito. Não grave uma chave real em arquivo versionado. `GEMINI_MODEL` é opcional; o padrão é `gemini-3.8-flash`, um modelo Flash multimodal do plano gratuito. Se ele responder 503 UNAVAILABLE ou 429, o servidor tenta uma vez `GEMINI_FALLBACK_MODEL` (padrão `gemini-3.5-flash-lite`, também gratuito) antes de mostrar o erro. A chamada sai do servidor Symfony, então a chave não chega ao navegador.

No formulário, abra **✨ Preencher com IA** no topo. Adicione pelo menos uma foto no restante do formulário. Grave uma descrição com **Gravar** (o botão fica vermelho, com um ponto e o tempo; toque de novo para parar, ouça e use **Enviar**) ou envie um arquivo WebM, MP3, M4A, WAV ou OGG de até 10 MB. As notas são livres e também opcionais. **Gerar perfil com IA** preenche um nome poético, uma descrição lírica e, de forma factual, dimensão, materiais, cuidados, feito à mão e, numa peça nova, o tamanho. A IA não inventa origem nem preço. As perguntas aparecem num painel de conversa, com o mesmo botão de gravar. Responda por texto ou áudio. Cada resposta segue com o histórico (guardado no navegador, não no servidor) e com os valores atuais do formulário. A IA atualiza o que ainda falta e faz a próxima pergunta, até dizer que o perfil está completo ou até você encerrar. Um campo que você editou à mão não é reescrito, a menos que a resposta peça essa mudança. Revise e só então salve.

A categoria (1, 2 ou 3) continua manual: ela só distingue a referência da peça e não tem um nome no arquivo. O preço de venda é opcional, em reais, e fica só no arquivo reservado e na planilha (formato `1.250,00` para o Excel em português). A página pública não mostra o preço, e a IA não preenche esse campo.

Se a chave faltar, a cota da API acabar ou o serviço falhar, o formulário mostra o motivo em português e continua editável.

## Exportar para a planilha

Na lista de artefactos, marque as peças e use **Exportar CSV** ou **Exportar XLSX**. O CSV é UTF-8 com BOM e separador `;`, para o Excel em português abrir os acentos. A planilha traz referência, nome, preço de venda, descrição, tamanho, categoria, dimensão, materiais, cuidados, feito à mão, data do registro, endereço público e a URL da foto principal. A última linha, **Total**, soma os preços. Uma peça sem preço entra como zero, e a linha diz quantas estão sem preço. No XLSX essa soma é a fórmula `SUM` com formato de real.

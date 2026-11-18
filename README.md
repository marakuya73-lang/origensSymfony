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

No formulário, a **foto principal** fica no topo: no telemóvel dá para usar a câmera ou a biblioteca, e a foto é gravada já na orientação certa. Abaixo, abra **✨ Preencher com IA**. Grave com o botão redondo (fica vermelho, com o tempo; a tela tenta permanecer acesa). Toque de novo para parar, ouça a duração e use **✨ Gerar perfil**, que manda a foto, o áudio — se houver, e só se a gravação não estiver vazia — e as notas juntos. Sem foto, um aviso no topo explica e oferece tentar de novo. A geração lê a foto e o áudio numa primeira passagem, só para separar os fatos. O que ela disser sobre sentido, propósito, intenção, para quem a peça serve ou a energia vai para um lugar próprio, e a segunda passagem escreve isso no campo Simbologia, sem perder frase. O nome sai da própria peça e varia de forma: uma palavra, duas, uma frase breve ou uma palavra tupi com o sentido. O banco em `data/symbolism.yaml` (`title_inspiration`) só abre o leque, e os nomes recentes do arquivo não são repetidos. A descrição não transcreve a lista de materiais: é prosa romântica, com a história e o sentido de cada material, cor, pena e pedra tecidos na frase. Perto do fim, uma frase evoca quem pode carregar a peça, sem lista e sem "indicado para". Cada material fica na sua linha; cada ave tem a sua pena; a nota diz o que aquele material é, sem repetir o nome no começo e sem "Na tradição". Aves e ossos saem com a grafia certa (bacurau, corujão-orelhudo, canela de égua). O campo **Simbologia**, se estiver preenchido, aparece na página pública. Dimensão e cuidados ficam factuais. A IA não inventa origem nem preço, não apaga o que foi contado e numa conversa seguinte só enriquece a descrição. As perguntas aparecem na conversa, com um único **Enviar resposta**. Nada é salvo até o botão de salvar.

A categoria (1, 2 ou 3) continua manual: ela só distingue a referência da peça e não tem um nome no arquivo. O preço de venda é opcional, em reais, e fica só no arquivo reservado e na planilha (formato `1.250,00` para o Excel em português). A página pública não mostra o preço, e a IA não preenche esse campo.

Se a chave faltar, a cota da API acabar ou o serviço falhar, o formulário mostra o motivo em português e continua editável.

## Acervo simbólico

Os sentidos tradicionais e o glossário da oficina ficam em `data/symbolism.yaml`, versionado com o código. A geração lê esse arquivo. Para ampliar o acervo, edite o YAML; não é preciso alterar PHP.

Cada lista (`stones`, `feathers`, `materials`, `colours`, `elements`, `chakras`, `signs`, `planets`, `intentions`) tem entradas assim:

```yaml
- id: ametista
  names: [ametista]
  brief: "Uma frase, usada quando o nome só aparece no áudio ou na foto."
  text: "O sentido tradicional, usado quando o nome já está nas notas, no formulário ou na conversa."
  related: [chakra-terceiro-olho]
```

`related` puxa só a frase (`brief`) da outra entrada. O pedido manda o texto inteiro das entradas reconhecidas no que ela já escreveu, e uma frase de cada pedra, pena, material, cor, elemento e intenção restante, para o modelo reconhecer o que ouve e o que vê sem receber o acervo inteiro. Chakras, signos e planetas só entram quando o nome aparece ou quando uma entrada reconhecida os liga.

O glossário corrige a grafia do texto devolvido e ensina a grafia certa:

```yaml
glossary:
  - term: abanilho
    mishearings: [abanilio, habanilho]
    text: "Leque ritual de penas, menor que o abano."
```

`abanilho` e `abano` são as grafias da oficina. `ábano`, a madeira, não é trocado.

O banco de nomes fica no mesmo arquivo, em `title_inspiration`. `avoid` lista palavras gastas. Cada grupo tem `entries` com `word` e `hint`. Para sugerir uma imagem nova, acrescente um item; o próximo perfil já o enxerga.

## Deploy

O banco e as fotos não ficam dentro do que o git atualiza. `var/` e `public/uploads/` estão no `.gitignore`. Um deploy que apaga arquivos não rastreados (`git clean`) ou que substitui a pasta por um clone novo apaga `var/data_prod.db` e as fotos. O `composer install` em seguida roda as migrations e cria um banco vazio. A migration da simbologia só acrescenta uma coluna; ela não apaga linhas.

No servidor, o caminho seguro é a pasta irmã do projeto, fora de `public_html`:

`/home/u505516867/domains/origens.art.br/origens-data/`

`bin/origens-pull` copia o banco para lá, grava o caminho em `.env.local`, faz um backup com data antes do `git pull` (guarda os 10 últimos e para se o backup falhar), liga `public/uploads` a essa pasta e só então migra. Não use `git clean`.

No hPanel da Hostinger, em **Git**, desligue o deploy automático. Um push não pode publicar sozinho: o clone novo apaga o que não está no git, inclusive o banco e as fotos. Quem publica é só este comando, à mão:

```bash
cd /home/u505516867/domains/origens.art.br/public_html
PHP_BIN=/opt/alt/php84/usr/bin/php ./bin/origens-pull
/opt/alt/php84/usr/bin/php bin/console origens:check --env=prod
```

`origens:check` mostra o arquivo do banco, se ele aceita gravação, quantas peças existem, a pasta das fotos e o último backup. O caminho tem de terminar em `origens-data/origens.sqlite`.

Um backup diário, além do que o deploy já faz:

```bash
15 4 * * * cd /home/u505516867/domains/origens.art.br/public_html && ./bin/origens-backup
```

O link público `/p/<token>` nasce uma vez, na criação, com 32 caracteres aleatórios guardados na peça. Editar, gerar de novo com a IA ou fazer deploy não troca esse token, e ele não volta a ser usado.

Quando um lote de peças estiver pronto, use **Baixar backup** no arquivo reservado. O ZIP `origens-backup-AAAAMMDD-HHMM.zip` traz o banco SQLite (cópia consistente), as fotos e a planilha CSV/XLSX com preço, simbologia, materiais e o link público, mais um `README.txt`. A lista mostra **Último backup baixado** e avisa se alguma peça foi gravada depois. No servidor, `bin/console origens:snapshot` grava o mesmo ZIP em `origens-data/snapshots` e não apaga as cópias antigas. Para voltar: `bin/console origens:restore CAMINHO.zip --force` (sem `--force` ele recusa, e antes copia o banco atual).

Se a gravação não for confirmada numa leitura nova do banco, o formulário mostra erro e não diz que salvou.

Os logs de produção vão para a saída de erro do PHP (`php://stderr`), não para `var/log/prod.log`.

## Exportar para a planilha

Na lista de artefactos, marque as peças e use **Exportar CSV** ou **Exportar XLSX**. O CSV é UTF-8 com BOM e separador `;`, para o Excel em português abrir os acentos. A planilha traz referência, nome, preço de venda, descrição, tamanho, categoria, dimensão, materiais, cuidados, feito à mão, data do registro, endereço público e a URL da foto principal. A última linha, **Total**, soma os preços. Uma peça sem preço entra como zero, e a linha diz quantas estão sem preço. No XLSX essa soma é a fórmula `SUM` com formato de real.

# Histórico de versões

O arquivo para download de cada versão está em [Releases](https://github.com/glpisalvador/catalogoeformularios/releases).

## 3.0.0 — 2026-10-05

Primeira versão como Catálogo e Formulários (sucessor do "Catálogo de Serviços"), autor GLPI Salvador, GLPI 11 e 12.

- **Editor completo no acordeão do formulário**, em abas:
  - Geral;
  - Estrutura: seções, perguntas, blocos de texto, arrastar entre seções;
  - Chamado gerado: campos, SLAs, OLAs, atores, destinos e condição de criação;
  - Quem visualiza.
- **Ícone do formulário**, com o mesmo seletor das categorias, na criação, na edição e na lista.
- **Todos os tipos de pergunta nativos**, incluindo requerente, observador, atribuído, dispositivo do usuário e lista do GLPI. Antes, esses tipos viravam texto curto ao editar.
- **Opções com chave preservada**: renomear ou reordenar não quebra condições nem valor padrão.
- **Valor padrão**, **validação da resposta** e **visibilidade** de perguntas, seções, blocos de texto e botão Enviar, com os operadores vindos do próprio tipo de pergunta.
- Correções:
  - "vazio/preenchido" eram gravados com nomes que o GLPI 12 não reconhece;
  - "Item do GLPI" era criado sem o tipo de item.
- Entidade dos formulários novos agora é configuração (antes era fixa no código).

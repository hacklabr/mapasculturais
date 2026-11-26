# Deviations of round R07 — Aplicar resultados das avaliações na habilitação documental
<!-- Every divergence between what was planned and what was implemented.
     An undeclared deviation is the embryo of contradictory documentation. -->

## Deviation 1 — Cast para inteiro no statusList do init.php
- **Planned:** o `init.php` do componente monta o `statusList` com `"status" => $status` (design.md §1, snippet original).
- **Implemented:** cast para inteiro (`"status" => (int) $status`).
- **Reason:** as chaves de `statusLabels` chegam como strings (JSON) e as de `defaultStatuses` como ints; sem o cast, a pré-seleção do status de destino por resultado (design §3.3) não casaria os valores no Vue quando a fase tem statusLabels configuradas. Motivo identificado pelo especialista na implementação (#145) e registrado no relatório da tarefa.
- **Decision registered at:** relatório final da tarefa #145 (2026-11-26).
- **Reference document updated:** `design.md` §1 — snippet do `init.php` atualizado com o cast + adendo datado de 2026-11-26.

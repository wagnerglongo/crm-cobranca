<?php
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
?>
            <section class="adaptor-work-grid">
                <div class="adaptor-action-column">
                    <form class="adaptor-event-box" method="post" action="<?= h(adaptorTabUrl((int) $contrato['id'], 'acionamento', $termoBusca, $tipoBusca)) ?>">
                        <?= authCsrfInput() ?>
                        <input type="hidden" name="acao" value="registrar_atendimento">
                        <input type="hidden" name="contrato_id" value="<?= (int) $contrato['id'] ?>">
                        <label class="adaptor-birthday-tag">▦ Registro operacional</label>
                        <textarea name="observacao" placeholder="Observação do acionamento (opcional). Use para registrar detalhes importantes."></textarea>
                        <div class="adaptor-form-row">
                            <label class="works-event-channel-field">Canal
                                <select name="canal">
                                    <?php foreach (AtendimentoRepository::CANAIS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="works-event-result-field">
                                <span class="works-field-label">Resultado <span class="works-inline-help" tabindex="0" data-help="Clique no campo para ver todas as opções ou digite parte do nome para filtrar. O resultado selecionado permanece no mesmo campo.">?</span></span>
                                <div class="works-event-picker" data-event-picker data-user-id="<?= (int) (usuarioAtualId() ?? 0) ?>">
                                    <div class="works-event-combobox">
                                        <span class="works-event-search-icon" aria-hidden="true">⌕</span>
                                        <input type="search" class="works-event-search" placeholder="Digite ou escolha um resultado..." autocomplete="off" required data-event-search role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="works-event-options" aria-label="Pesquisar e selecionar resultado">
                                        <button type="button" class="works-event-favorite-current" data-event-favorite-current hidden title="Favoritar resultado" aria-label="Favoritar resultado">☆</button>
                                    </div>
                                    <select name="resultado_ref" data-event-select tabindex="-1" aria-hidden="true">
                                        <option value="">Selecione uma tabulação</option>
                                        <?php if ($resultadosAtendimento === []): ?>
                                            <?php foreach (AtendimentoRepository::RESULTADOS as $codigo => $label): ?>
                                                <option value="legacy:<?= h($codigo) ?>" data-label="<?= h($label) ?>" data-category="Legado" data-cpc="0"><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <?php foreach ($resultadosAtendimento as $resultado): ?>
                                                <?php $value = worksResultadoRef($resultado); ?>
                                                <?php $pedeMotivo = (int) ($resultado['considera_contato_efetivo'] ?? 0) === 1 && (int) ($resultado['considera_sucesso'] ?? 0) === 0 && $acordoAtivo === null; ?>
                                                <option value="<?= h($value) ?>" data-label="<?= h((string) $resultado['nome']) ?>" data-category="<?= h((string) ($resultado['categoria'] ?? '')) ?>" data-cpc="<?= $pedeMotivo ? 1 : 0 ?>" data-reagenda="<?= (int) ($resultado['gera_reagendamento'] ?? 0) === 1 ? 1 : 0 ?>"><?= h((string) $resultado['nome']) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <span class="works-event-selection-status sr-only" data-event-selected aria-live="polite"></span>
                                    <div class="works-event-suggestions" id="works-event-options" data-event-suggestions role="listbox" hidden></div>
                                    <div class="works-event-quick" data-event-quick></div>
                                </div>
                            </label>
                            <label class="works-event-contact-field">Contato usado
                                <select name="contato_id" required>
                                    <option value="">Selecione o contato usado</option>
                                    <?php foreach ($contatosAcionaveis as $contato): ?>
                                        <option value="<?= (int) $contato['id'] ?>"><?= h(adaptorContatoLabel($contato)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="works-event-return-field" data-reagenda-field><span>Retorno</span>
                                <input type="datetime-local" name="proximo_acionamento" data-reagenda-input>
                            </label>
                            <label class="works-event-motivo-field" data-motivo-field hidden>Motivo da inadimplência
                                <select name="motivo_inadimplencia_id" data-motivo-select>
                                    <option value="">Selecione o motivo</option>
                                    <?php foreach ($motivosInadimplencia as $motivo): ?>
                                        <option value="<?= (int) $motivo['id'] ?>"><?= h((string) $motivo['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="works-field-help">Houve contato com a pessoa certa (CPC) - por que o acordo não fechou?</small>
                            </label>
                        </div>
                        <button class="adaptor-green-btn" type="submit">Registrar Evento <span class="works-button-shortcut">Ctrl+Enter</span></button>
                    </form>

                    <div class="adaptor-timeline-title works-action-title"><strong>ACIONAMENTO</strong></div>
                    <?php if ($financeiroPerfil === 'acme'): ?>
                        <div class="works-acme-history-error" data-acme-tracking-status aria-live="polite" hidden></div>
                    <?php endif; ?>
                    <div class="adaptor-timeline"
                        <?php if ($financeiroPerfil === 'acme'): ?>
                            data-acme-unified-timeline
                            data-acme-tracking-url="<?= h($acionamentosAcmeEndpoint) ?>"
                        <?php endif; ?>
                    >
                        <?php if ($historico === []): ?>
                            <div class="adaptor-empty-box">Nenhum acionamento registrado para este contrato/cliente.</div>
                        <?php else: ?>
                            <?php foreach ($historico as $item): ?>
                                <article class="adaptor-timeline-item" data-history-source="works">
                                    <div class="adaptor-timeline-date"><strong><?= h(dataBr($item['criado_em'] ?? null)) ?></strong><small><?= h((new DateTimeImmutable((string) $item['criado_em']))->format('H:i:s')) ?></small></div>
                                    <div class="adaptor-timeline-dot">&#9759;</div>
                                    <div class="adaptor-timeline-card">
                                        <div class="works-history-card-head">
                                            <h3><?= h(strtoupper((string) ($item['resultado_nome'] ?: AtendimentoRepository::resultadoLabel($item['resultado'] ?? null)))) ?></h3>
                                            <?php if ($financeiroPerfil === 'acme'): ?><span class="works-history-source is-works">Pactua</span><?php endif; ?>
                                        </div>
                                        <p><?= h(AtendimentoRepository::canalLabel($item['canal'] ?? null)) ?><?= !empty($item['contato_utilizado']) ? ' - ' . h((string) $item['contato_utilizado']) : '' ?></p>
                                        <?php if (!empty($item['observacao'])): ?><p><?= nl2br(h($item['observacao'])) ?></p><?php endif; ?>
                                        <?php if (!empty($item['proximo_acionamento'])): ?><span class="adaptor-mini-btn">Retorno: <?= h(dataHoraBr($item['proximo_acionamento'])) ?></span><?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>

                <aside class="adaptor-side-client">
                    <div class="adaptor-side-section">
                        <small>♟ NOME:</small>
                        <div class="works-copy-line">
                            <h2><?= h($contrato['pessoa_nome']) ?></h2>
                            <button type="button" class="works-copy-btn works-copy-btn-small" data-copy-value="<?= h((string) $contrato['pessoa_nome']) ?>" data-copy-label="Nome copiado">Copiar</button>
                        </div>
                    </div>
                    <div class="adaptor-side-two">
                        <div>
                            <small>CPF / CNPJ:</small>
                            <span class="works-copy-line">
                                <strong><?= documentoFormatado((string) $contrato['pessoa_documento']) ?></strong>
                                <button type="button" class="works-copy-btn works-copy-btn-small" data-copy-value="<?= h((string) $contrato['pessoa_documento']) ?>" data-copy-label="CPF/CNPJ copiado">Copiar</button>
                            </span>
                        </div>
                        <div><small>CÓDIGO DO CLIENTE NO CREDOR:</small><strong><?= h($contrato['devedor_origem_id'] ?: '-') ?></strong></div>
                    </div>
                    <div class="adaptor-side-two">
                        <div><small>CREDOR:</small><strong><?= h($contrato['credor_nome']) ?></strong></div>
                        <div><small>PRODUTO:</small><strong><?= h($contrato['produto'] ?: '-') ?></strong></div>
                    </div>
                    <div class="adaptor-side-two">
                        <div><small>VALOR DA DÍVIDA:</small><strong data-acme-debt><?= h(dinheiro($contrato['saldo_atual'])) ?></strong></div>
                        <div><small>Nº:</small><strong><?= h($contrato['conta_id'] ?: '-') ?></strong></div>
                    </div>
                    <div class="adaptor-side-two">
                        <div><small>ATRASO:</small><strong data-acme-delay><?= h($atrasoContrato['label']) ?></strong><em class="works-delay-note" data-acme-delay-note><?= h($atrasoContrato['nota']) ?></em></div>
                        <div><small>ÚLTIMO ACIONAMENTO:</small><strong><?= h(dataHoraBr($contrato['ultimo_atendimento_em'] ?? null)) ?></strong></div>
                    </div>
                    <div class="adaptor-side-section">
                        <small>SITUAÇÃO DA DÍVIDA:</small>
                        <strong><?= h(adaptorContratoStatusOperacional($contrato)) ?></strong>
                    </div>
                    <div class="adaptor-side-section">
                        <small>CÓDIGO CONTRATO:</small>
                        <strong><?= h($contrato['codigo_contrato']) ?></strong>
                    </div>
                    <a class="adaptor-side-button" href="<?= h(adaptorTabUrl((int) $contrato['id'], 'financeiro', $termoBusca, $tipoBusca)) ?>">Abrir financeiro</a>
                </aside>
            </section>

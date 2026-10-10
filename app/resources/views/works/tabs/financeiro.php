<?php
use CRM\Modules\Operacao\Repositories\AcordoOperacionalRepository;
use CRM\Modules\Operacao\Repositories\AtendimentoRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
?>
            <section class="adaptor-financeiro works-financeiro"
                data-financeiro-perfil="<?= h($financeiroPerfil) ?>"
                data-acme-api-url="<?= h($financeiroApiEndpoint) ?>"
                data-contrato-id="<?= (int) $contrato['id'] ?>"
                data-acme-refresh-window-seconds="600"
                data-acme-force-agreement="<?= $forcarValidacaoAcordoAcme ? '1' : '0' ?>"
                data-retirado-credor="<?= $contratoRetiradoCredor ? '1' : '0' ?>"
                data-pode-copiar-boleto="<?= $podeCopiarBoleto ? '1' : '0' ?>"
                data-pode-baixar-boleto="<?= $podeBaixarBoleto ? '1' : '0' ?>">
                <div class="adaptor-debt-strip works-finance-header">
                    <div><strong>Financeiro</strong><span>Contrato acionado: <?= h($contrato['codigo_contrato']) ?> · Perfil <?= h(strtoupper($financeiroPerfil)) ?></span></div>
                    <div class="adaptor-debt-badge">Selecionado</div>
                    <?php if ($acordoAtivo !== null): ?><div class="adaptor-debt-badge danger">COM PROMESSA ATIVA #<?= (int) $acordoAtivo['id'] ?></div><?php endif; ?>
                </div>

                <div class="works-finance-api-status" data-acme-api-status>
                    <?php if ($financeiroPerfil === 'acme'): ?>
                        <span class="works-spinner tiny" aria-hidden="true"></span>
                        <strong>Carregando financeiro do CRM...</strong>
                    <?php else: ?>
                        <strong>Perfil financeiro padrão.</strong> Este credor/produto ainda não possui layout financeiro específico.
                    <?php endif; ?>
                </div>

                <div class="table-scroll adaptor-parcelas-box works-original-installments"><table class="adaptor-table">
                    <thead><tr><th>Selecionar</th><th>Parcela</th><th>Valor</th><th>Vencimento</th><th>Atraso</th><th>Dt Entrada</th><th>Valor Original</th><th>Status</th><th>Origem</th></tr></thead>
                    <tbody>
                    <?php foreach ($parcelasOriginais as $parcela): ?>
                        <?php
                            $atraso = '-';
                            if (!empty($parcela['data_vencimento'])) {
                                try {
                                    $dias = (new DateTimeImmutable((string) $parcela['data_vencimento']))->diff(new DateTimeImmutable('today'))->format('%r%a');
                                    $atraso = (string) max(0, (int) $dias);
                                } catch (Throwable) { $atraso = '-'; }
                            }
                            if (!empty($parcela['fallback_base_contrato'])) {
                                $atraso = (string) $atrasoContrato['label'];
                            } elseif ($atraso === '-' && isset($contrato['dias_atraso'])) {
                                $atraso = (string) $atrasoContrato['label'];
                            }
                        ?>
                        <tr>
                            <td><span class="adaptor-selected-cell">Selecionado</span></td>
                            <td><?= h($parcela['numero_parcela'] ?: '1') ?></td>
                            <td><?= h(dinheiro($parcela['saldo_parcela'] ?? $valorBaseAcordo)) ?></td>
                            <td><?= h(dataBr($parcela['data_vencimento'] ?? null)) ?></td>
                            <td><?= h($atraso) ?></td>
                            <td><?= h(dataBr($contrato['data_bordero'] ?? null)) ?></td>
                            <td><?= h(dinheiro($parcela['valor_original'] ?? ($parcela['saldo_parcela'] ?? $valorBaseAcordo))) ?></td>
                            <td><?= h($parcela['status_parcela'] ?: ($contrato['status_contrato'] ?: '-')) ?></td>
                            <td><?= !empty($parcela['fallback_base_contrato']) ? 'Base contrato' : 'Parcela importada' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>

                <section class="works-current-agreement works-current-agreement-visible" data-current-agreement-panel>
                    <div class="works-current-agreement-head">
                        <div>
                            <strong>Acordo retornado pela ACME</strong>
                            <span data-current-agreement-subtitle>Validando situação, pagamentos e prazo de quebra no credor.</span>
                        </div>
                        <span class="works-agreement-status" data-current-agreement-status>Validando</span>
                    </div>
                    <div class="works-current-agreement-empty" data-current-agreement-empty>
                        Ao abrir o contrato pela busca, o Pactua consulta a ACME. Se existir acordo vigente, os detalhes aparecem aqui.
                    </div>
                    <div class="works-current-agreement-summary" data-current-agreement-summary hidden></div>
                    <div class="works-current-agreement-installments-card" data-current-agreement-installments-card hidden>
                        <h4>Parcelas atuais do acordo</h4>
                        <div class="table-scroll">
                            <table class="adaptor-table works-current-agreement-table">
                                <thead><tr><th>Parcela</th><th>Valor</th><th>Vencimento</th><th>Pago em</th><th>Valor pago</th><th>Prazo de quebra ACME</th><th>Status conciliado</th><th>Boleto</th></tr></thead>
                                <tbody data-current-agreement-installments></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="works-boleto-copy" data-boleto-copy-box hidden>
                        <strong>Linha digitavel</strong>
                        <textarea readonly data-boleto-line></textarea>
                        <button type="button" class="works-secondary-btn" data-copy-boleto-line>Copiar linha digitavel</button>
                    </div>
                </section>

                <div class="works-financial-timeline">
                    <div class="works-section-header compact">
                        <div>
                            <strong>Histórico financeiro do contrato</strong>
                            <span>Acordos, atribuições e ações de boleto feitas no Pactua.</span>
                        </div>
                    </div>
                    <?php if ($historicoFinanceiro === []): ?>
                        <p class="muted">Nenhum evento financeiro registrado ainda para este contrato.</p>
                    <?php else: ?>
                        <?php foreach ($historicoFinanceiro as $eventoFinanceiro): ?>
                            <article class="works-financial-event <?= h($eventoFinanceiro['classe']) ?>">
                                <div><strong><?= h($eventoFinanceiro['titulo']) ?></strong><span><?= h($eventoFinanceiro['detalhe']) ?></span></div>
                                <small><?= h(dataHoraBr($eventoFinanceiro['quando'] ?: null)) ?> · <?= h($eventoFinanceiro['usuario']) ?></small>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>


                <?php if ($acordoAtivo === null && !$contratoRetiradoCredor): ?>
                    <div class="adaptor-finance-grid works-finance-grid">
                        <div class="adaptor-negotiation-card works-negotiation-card" data-new-agreement-card>
                            <h3>Nova promessa de pagamento</h3>

                            <form class="adaptor-agreement-form works-proposal-form" method="post" action="<?= h(adaptorTabUrl((int) $contrato['id'], 'financeiro', $termoBusca, $tipoBusca)) ?>" data-works-proposal-form>
                                <?= authCsrfInput() ?>
                                <input type="hidden" name="acao" value="registrar_acordo">
                                <input type="hidden" name="contrato_id" value="<?= (int) $contrato['id'] ?>">
                                <input type="hidden" name="proposta_id" data-field="proposta_id" value="">
                                <input type="hidden" name="proposta_descricao" data-field="proposta_descricao" value="">
                                <input type="hidden" name="valor_acordo" data-field="valor_acordo" value="<?= h(number_format($valorBaseAcordo, 2, ',', '.')) ?>">
                                <input type="hidden" name="quantidade_parcelas" data-field="quantidade_parcelas" value="1">
                                <input type="hidden" name="primeiro_vencimento_iso" data-field="primeiro_vencimento_iso" value="">
                                <input type="hidden" name="data_primeira_parcela" data-field="data_primeira_parcela" value="<?= h($proximaParcelaPadrao) ?>">
                                <input type="hidden" name="tipo_acordo_id" data-field="tipo_acordo_id" value="">
                                <input type="hidden" name="tipo_acordo_descricao" data-field="tipo_acordo_descricao" value="">
                                <input type="hidden" name="tipo_pagamento_tag" data-field="tipo_pagamento_tag" value="">
                                <input type="hidden" name="tipo_pagamento_descricao" data-field="tipo_pagamento_descricao" value="">
                                <input type="hidden" name="forma_recebimento_tag" data-field="forma_recebimento_tag" value="">
                                <input type="hidden" name="forma_recebimento_descricao" data-field="forma_recebimento_descricao" value="">
                                <input type="hidden" name="canal_acionamento" data-field="canal_acionamento" value="ligacao">
                                <input type="hidden" name="operador_responsavel_id" data-field="operador_responsavel_id" value="<?= (int) $usuarioAtualIdAcordo ?>">
                                <input type="hidden" name="operador_responsavel_nome" data-field="operador_responsavel_nome" value="<?= h($usuarioAtualNomeAcordo) ?>">

                                <fieldset>
                                    <div class="works-copy-options-bar">
                                        <button type="button" class="button secondary small" data-copy-proposal-options>Copiar opções p/ enviar ao cliente</button>
                                        <span class="works-copy-options-feedback" data-copy-proposal-feedback hidden>Copiado!</span>
                                    </div>
                                    <div class="works-api-form-grid">
                                        <label>Propostas
                                            <select name="proposta_select" data-proposal-select required>
                                                <option value="">Carregando propostas...</option>
                                            </select>
                                        </label>
                                        <label>1º Vencimento
                                            <select name="primeiro_vencimento_select" data-date-select required>
                                                <option value="">Carregando datas...</option>
                                            </select>
                                        </label>
                                        <label>Tipo de Acordo
                                            <select name="tipo_acordo_select" data-agreement-type-select required>
                                                <option value="">Carregando tipos...</option>
                                            </select>
                                        </label>
                                        <label>Tipo de pagamento
                                            <select name="tipo_pagamento_select" data-payment-type-select required>
                                                <option value="">Carregando pagamento...</option>
                                            </select>
                                        </label>
                                        <label>Forma de recebimento do boleto
                                            <select name="forma_recebimento_select" data-slip-receive-select required>
                                                <option value="">Carregando recebimento...</option>
                                            </select>
                                        </label>
                                        <label>Email
                                            <select name="email_promessa">
                                                <option value="">Não selecionado</option>
                                                <?php foreach ($emailsFinanceiro as $emailContato): ?>
                                                    <option value="<?= h((string) $emailContato['valor']) ?>"><?= h((string) $emailContato['valor']) ?><?= ((int) ($emailContato['principal'] ?? 0)) === 1 ? ' · principal' : '' ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Telefone
                                            <select name="telefone_promessa">
                                                <option value="">Não selecionado</option>
                                                <?php foreach ($telefonesFinanceiro as $telefoneContato): ?>
                                                    <option value="<?= h((string) $telefoneContato['valor']) ?>"><?= h(ConsultaClienteRepository::contatoTipoLabel((string) $telefoneContato['tipo'])) ?> · <?= h((string) $telefoneContato['valor']) ?><?= ((int) ($telefoneContato['principal'] ?? 0)) === 1 ? ' · principal' : '' ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Observação
                                            <input type="text" name="observacao" placeholder="Observação interna da promessa">
                                        </label>
                                    </div>
                                    <div class="works-selected-proposal" data-selected-proposal>
                                        Selecione uma proposta para visualizar o resumo antes de concluir.
                                    </div>
                                    <button class="adaptor-green-btn wide" type="submit" data-proposal-submit>Concluir e enviar promessa</button>
                                </fieldset>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="works-modal-backdrop" id="works-promessa-modal" data-works-modal hidden>
                    <div class="works-modal-card works-promessa-card">
                        <div class="works-modal-head">
                            <strong>Confirmar promessa de pagamento</strong>
                            <button type="button" data-works-modal-close aria-label="Fechar">×</button>
                        </div>
                        <div class="works-promise-confirm-body">
                            <div class="works-promise-summary" data-promise-summary></div>
                            <label>Canal do acionamento
                                <select data-promise-channel>
                                    <?php foreach (AtendimentoRepository::CANAIS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <?php if ($podeAtribuirAcordo): ?>
                                <label>Responsável pelo acordo
                                    <select data-promise-responsavel>
                                        <?php foreach ($usuariosAtribuiveisAcordo as $usuarioAtribuivel): ?>
                                            <option value="<?= (int) $usuarioAtribuivel['id'] ?>" data-nome="<?= h((string) $usuarioAtribuivel['nome']) ?>" <?= (int) $usuarioAtribuivel['id'] === (int) $usuarioAtualIdAcordo ? 'selected' : '' ?>>
                                                <?= h((string) $usuarioAtribuivel['nome']) ?><?= trim((string) ($usuarioAtribuivel['email'] ?? '')) !== '' ? ' · ' . h((string) $usuarioAtribuivel['email']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <p class="muted">Somente perfis autorizados conseguem atribuir a promessa para outro operador. Quem fechou continua registrado como o usuário logado.</p>
                            <?php else: ?>
                                <p class="muted">Responsável pelo acordo: <strong><?= h($usuarioAtualNomeAcordo) ?></strong>.</p>
                            <?php endif; ?>
                            <p class="muted">Ao confirmar, o sistema envia a proposta para a ACME, salva a promessa internamente e registra o acionamento como Promessa de pagamento.</p>
                        </div>
                        <div class="works-modal-actions works-promise-actions">
                            <button type="button" class="works-secondary-btn" data-works-modal-close>Voltar</button>
                            <button type="button" class="adaptor-green-btn" data-promise-confirm>Confirmar e enviar</button>
                        </div>
                    </div>
                </div>
            </section>

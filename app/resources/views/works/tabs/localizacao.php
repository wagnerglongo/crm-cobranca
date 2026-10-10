<?php
use CRM\Modules\Operacao\Repositories\AtendimentoAdaptorRepository;
use CRM\Modules\Operacao\Repositories\ConsultaClienteRepository;
?>
            <section class="adaptor-location-panel works-location-panel">
                <div class="adaptor-toolbar works-toolbar-title">
                    <button type="button">Localizacao</button>
                    <span>Categorias e situacoes padronizadas. A origem mostra carga/lote quando o contato veio do importador.</span>
                </div>

                <?php if ($financeiroPerfil === 'acme'): ?>
                    <div class="works-location-api-sync" data-acme-location-sync data-acme-location-url="<?= h($localizacaoAcmeEndpoint) ?>">
                        <div>
                            <strong>Localização ACME</strong>
                            <span>O Pactua usa primeiro os dados salvos no CRM. A API externa só é consultada aqui quando você solicitar.</span>
                        </div>
                        <button type="button" class="works-secondary-btn" data-acme-location-refresh>Atualizar pela ACME</button>
                        <div class="works-location-api-status" data-acme-location-status>
                            <strong>Dados locais carregados. Nenhuma chamada externa foi feita.</strong>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="works-location-summary">
                    <span><strong><?= count($contatos) ?></strong> contato(s)</span>
                    <span><strong><?= count(array_filter($contatos, static fn (array $c): bool => worksContatoPrioridade($c)['classe'] === 'hot')) ?></strong> HOT</span>
                    <span><strong><?= count(array_filter($contatos, static fn (array $c): bool => worksContatoPrioridade($c)['classe'] === 'valid')) ?></strong> validado(s)</span>
                    <span><strong><?= count($enderecos) ?></strong> endereço(s)</span>
                    <?php if (!$podeEditarLocalizacao): ?><span class="warning-text">Seu perfil permite consulta, mas não edição de localização.</span><?php endif; ?>
                </div>

                <div class="works-section-header">
                    <div>
                        <strong>Contatos</strong>
                        <span>Telefones, WhatsApp e e-mails do cliente.</span>
                    </div>
                    <?php if ($podeEditarLocalizacao): ?><button type="button" class="works-add-plus" data-works-modal-open="works-contato-modal" aria-label="Adicionar contato">+</button><?php endif; ?>
                </div>
                <div class="table-scroll"><table class="adaptor-table works-edit-table works-location-table">
                    <thead><tr><th>Prioridade</th><th>Canal</th><th>Contato</th><th>Categoria</th><th>Situacao</th><th>Origem</th><th>Principal</th><th>Acao</th></tr></thead>
                    <tbody>
                    <?php foreach ($contatos as $contato): ?>
                        <?php
                            $formId = 'contato-form-' . (int) $contato['id'];
                            $categoriaAtual = adaptorContatoCategoriaAtual($contato);
                            $situacaoAtual = adaptorContatoSituacaoAtual($contato);
                            $origemContato = adaptorOrigemContatoLabel($contato);
                            $prioridadeContato = worksContatoPrioridade($contato);
                        ?>
                        <form id="<?= h($formId) ?>" method="post" action="<?= h(adaptorTabUrl((int) $contrato['id'], 'localizacao', $termoBusca, $tipoBusca)) ?>">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="atualizar_contato">
                            <input type="hidden" name="contrato_id" value="<?= (int) $contrato['id'] ?>">
                            <input type="hidden" name="contato_id" value="<?= (int) $contato['id'] ?>">
                        </form>
                        <tr class="works-location-priority-<?= h($prioridadeContato['classe']) ?>">
                            <td><span class="works-priority-chip <?= h($prioridadeContato['classe']) ?>"><?= h($prioridadeContato['label']) ?></span></td>
                            <td><?= h(ConsultaClienteRepository::contatoTipoLabel((string) $contato['tipo'])) ?> <?= (string) $contato['status'] === 'hot' ? '★' : '' ?></td>
                            <td><strong><?= h($contato['valor']) ?></strong><br><small><?= h($contato['valor_normalizado'] ?: '') ?></small></td>
                            <td>
                                <select form="<?= h($formId) ?>" name="subtipo">
                                    <?php foreach (AtendimentoAdaptorRepository::CONTATO_CATEGORIAS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>" <?= $categoriaAtual === $codigo ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select form="<?= h($formId) ?>" name="situacao">
                                    <?php foreach (AtendimentoAdaptorRepository::CONTATO_SITUACOES as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>" <?= $situacaoAtual === $codigo ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="works-origin-cell">
                                <strong><?= h($origemContato) ?></strong>
                                <small>Classificacao carga: <?= h(ConsultaClienteRepository::contatoStatusLabel((string) ($contato['status'] ?? 'novo'))) ?></small>
                                <?php if (!empty($contato['atualizado_em'])): ?><small>Atualizado: <?= h(dataHoraBr($contato['atualizado_em'])) ?></small><?php endif; ?>
                            </td>
                            <td><label class="works-check compact"><input form="<?= h($formId) ?>" type="checkbox" name="principal" value="1" <?= ((int) ($contato['principal'] ?? 0)) === 1 ? 'checked' : '' ?>> Principal</label></td>
                            <td><?php if ($podeEditarLocalizacao): ?><button form="<?= h($formId) ?>" class="adaptor-green-mini" type="submit">Salvar</button><?php else: ?><span class="muted">Sem permissão</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($contatos === []): ?><tr><td colspan="8">Nenhum contato encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>

                <div class="works-section-header">
                    <div>
                        <strong>Enderecos</strong>
                        <span>Enderecos importados ou incluidos manualmente.</span>
                    </div>
                    <?php if ($podeEditarLocalizacao): ?><button type="button" class="works-add-plus" data-works-modal-open="works-endereco-modal" aria-label="Adicionar endereco">+</button><?php endif; ?>
                </div>
                <div class="table-scroll"><table class="adaptor-table works-edit-table works-location-table">
                    <thead><tr><th>Principal</th><th>Endereco completo</th><th>Categoria</th><th>Origem</th><th>Acao</th></tr></thead>
                    <tbody>
                    <?php foreach ($enderecos as $endereco): ?>
                        <?php
                            $enderecoFormId = 'endereco-form-' . (int) $endereco['id'];
                            $enderecoCategoriaAtual = adaptorEnderecoCategoriaAtual($endereco);
                            $origemEndereco = trim((string) ($endereco['origem'] ?? '')) ?: 'manual';
                        ?>
                        <form id="<?= h($enderecoFormId) ?>" method="post" action="<?= h(adaptorTabUrl((int) $contrato['id'], 'localizacao', $termoBusca, $tipoBusca)) ?>">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="atualizar_endereco">
                            <input type="hidden" name="contrato_id" value="<?= (int) $contrato['id'] ?>">
                            <input type="hidden" name="endereco_id" value="<?= (int) $endereco['id'] ?>">
                        </form>
                        <tr>
                            <td><label class="works-check compact"><input form="<?= h($enderecoFormId) ?>" type="checkbox" name="principal" value="1" <?= ((int) ($endereco['principal'] ?? 0)) === 1 ? 'checked' : '' ?>> Principal</label></td>
                            <td><?= h(adaptorEnderecoCompleto($endereco)) ?></td>
                            <td>
                                <select form="<?= h($enderecoFormId) ?>" name="tipo">
                                    <?php foreach (AtendimentoAdaptorRepository::ENDERECO_CATEGORIAS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>" <?= $enderecoCategoriaAtual === $codigo ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="works-origin-cell"><strong><?= h($origemEndereco) ?></strong><?php if (!empty($endereco['atualizado_em'])): ?><small>Atualizado: <?= h(dataHoraBr($endereco['atualizado_em'])) ?></small><?php endif; ?></td>
                            <td><?php if ($podeEditarLocalizacao): ?><button form="<?= h($enderecoFormId) ?>" class="adaptor-green-mini" type="submit">Salvar</button><?php else: ?><span class="muted">Sem permissão</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($enderecos === []): ?><tr><td colspan="5">Nenhum endereco encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>

                <div class="works-modal-backdrop" id="works-contato-modal" data-works-modal hidden>
                    <div class="works-modal-card" role="dialog" aria-modal="true" aria-labelledby="works-contato-title">
                        <div class="works-modal-head">
                            <strong id="works-contato-title">Adicionar contato</strong>
                            <button type="button" data-works-modal-close aria-label="Fechar">×</button>
                        </div>
                        <form class="works-modal-form" method="post" action="<?= h(adaptorTabUrl((int) $contrato['id'], 'localizacao', $termoBusca, $tipoBusca)) ?>">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="adicionar_contato">
                            <input type="hidden" name="contrato_id" value="<?= (int) $contrato['id'] ?>">
                            <label>Tipo
                                <select name="tipo" required>
                                    <?php foreach (ConsultaClienteRepository::CONTATO_TIPOS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Contato
                                <input type="text" name="valor" placeholder="Telefone, WhatsApp ou e-mail" required>
                            </label>
                            <label>Categoria
                                <select name="subtipo">
                                    <?php foreach (AtendimentoAdaptorRepository::CONTATO_CATEGORIAS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Situacao
                                <select name="situacao">
                                    <?php foreach (AtendimentoAdaptorRepository::CONTATO_SITUACOES as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="works-check"><input type="checkbox" name="principal" value="1"> Marcar como principal</label>
                            <div class="works-modal-actions">
                                <button type="button" class="works-secondary-btn" data-works-modal-close>Cancelar</button>
                                <button class="adaptor-green-btn" type="submit">Adicionar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="works-modal-backdrop" id="works-endereco-modal" data-works-modal hidden>
                    <div class="works-modal-card wide" role="dialog" aria-modal="true" aria-labelledby="works-endereco-title">
                        <div class="works-modal-head">
                            <strong id="works-endereco-title">Adicionar endereco</strong>
                            <button type="button" data-works-modal-close aria-label="Fechar">×</button>
                        </div>
                        <form class="works-modal-form works-modal-grid" method="post" action="<?= h(adaptorTabUrl((int) $contrato['id'], 'localizacao', $termoBusca, $tipoBusca)) ?>">
                            <?= authCsrfInput() ?>
                            <input type="hidden" name="acao" value="adicionar_endereco">
                            <input type="hidden" name="contrato_id" value="<?= (int) $contrato['id'] ?>">
                            <label>Categoria
                                <select name="tipo">
                                    <?php foreach (AtendimentoAdaptorRepository::ENDERECO_CATEGORIAS as $codigo => $label): ?>
                                        <option value="<?= h($codigo) ?>" <?= $codigo === 'residencial' ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>CEP<input type="text" name="cep" placeholder="00000-000"></label>
                            <label>UF<input type="text" name="uf" maxlength="2" placeholder="SP"></label>
                            <label>Cidade<input type="text" name="cidade" placeholder="Cidade"></label>
                            <label>Bairro<input type="text" name="bairro" placeholder="Bairro"></label>
                            <label>Logradouro<input type="text" name="logradouro" placeholder="Rua, avenida..."></label>
                            <label>Numero<input type="text" name="numero" placeholder="Nº"></label>
                            <label>Complemento<input type="text" name="complemento" placeholder="Complemento"></label>
                            <label class="works-check"><input type="checkbox" name="principal" value="1"> Marcar como principal</label>
                            <div class="works-modal-actions full">
                                <button type="button" class="works-secondary-btn" data-works-modal-close>Cancelar</button>
                                <button class="adaptor-green-btn" type="submit">Adicionar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

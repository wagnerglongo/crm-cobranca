<?php

declare(strict_types=1);

use CRM\Modules\Operacao\Repositories\AtendimentoRepository;

// Controlador transicional de comandos do Pactua. Mantem a mesma transacao e
// semantica do entrypoint original enquanto a leitura/view ja seguem a camada modular.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validarCsrf();
        $acaoPost = (string) ($_POST['acao'] ?? '');
        $contratoPostId = adaptorInt($_POST['contrato_id'] ?? null);
        if ($contratoPostId <= 0) {
            throw new RuntimeException('Contrato não informado para a ação operacional.');
        }

        $contratoPost = $repository->buscarContrato($contratoPostId, $escopoConsulta);
        if ($contratoPost === null) {
            throw new RuntimeException('Contrato não encontrado ou fora do seu escopo.');
        }

        $estrategiaItemPost = null;
        if ($estrategiaItemId > 0) {
            if (!$estrategiaRepository->moduloDisponivel()) {
                throw new RuntimeException('O módulo de estratégias ainda não está disponível. Execute a migration 083.');
            }
            $estrategiaItemPost = $estrategiaRepository->buscarItem(
                $estrategiaItemId,
                usuarioAtualId() ?? 0,
                $escopoOperacao
            );
            if ($estrategiaItemPost === null || (int) ($estrategiaItemPost['contrato_id'] ?? 0) !== $contratoPostId) {
                throw new RuntimeException('O item de estratégia não corresponde a este contrato ou está fora do seu escopo.');
            }
            $usuarioOperadorId = usuarioAtualId() ?? 0;
            if ((string) ($estrategiaItemPost['estrategia_status'] ?? '') !== 'ativa') {
                throw new RuntimeException('A estratégia foi pausada ou encerrada. Volte à página Estratégia antes de continuar.');
            }
            if ((string) ($estrategiaItemPost['status_operacional'] ?? '') !== 'em_atendimento'
                || (int) ($estrategiaItemPost['reservado_por_usuario_id'] ?? 0) !== $usuarioOperadorId) {
                throw new RuntimeException('Este contrato não está reservado para o seu usuário. Abra-o novamente pela página Estratégia.');
            }
        }

        if (in_array($acaoPost, ['adicionar_contato','atualizar_contato','adicionar_endereco','atualizar_endereco'], true)) {
            if (!usuarioPode('atendimentos.registrar')) {
                throw new RuntimeException('Seu perfil não possui permissão para alterar contatos/localização.');
            }

            $pessoaPostId = (int) $contratoPost['pessoa_id'];

            if ($acaoPost === 'adicionar_contato') {
                $contatoId = $consultaRepository->adicionarContatoManual($pessoaPostId, [
                    'tipo' => (string) ($_POST['tipo'] ?? ''),
                    'valor' => (string) ($_POST['valor'] ?? ''),
                    'subtipo' => (string) ($_POST['subtipo'] ?? ''),
                    'status' => 'novo',
                    'principal' => isset($_POST['principal']) ? '1' : '0',
                    'observacao' => 'Incluído pela tela Pactua atendimento.',
                ]);

                if ($contatoId > 0) {
                    $repository->atualizarContatoWorks($pessoaPostId, $contatoId, [
                        'subtipo' => (string) ($_POST['subtipo'] ?? ''),
                        'situacao' => (string) ($_POST['situacao'] ?? ''),
                        'principal' => isset($_POST['principal']) ? '1' : '0',
                    ]);
                }

                header('Location: ' . adaptorTabUrl($contratoPostId, 'localizacao', $termoBusca, $tipoBusca) . '&ok=contato');
                exit;
            }

            if ($acaoPost === 'atualizar_contato') {
                $repository->atualizarContatoWorks($pessoaPostId, adaptorInt($_POST['contato_id'] ?? null), [
                    'subtipo' => (string) ($_POST['subtipo'] ?? ''),
                    'situacao' => (string) ($_POST['situacao'] ?? ''),
                    'principal' => isset($_POST['principal']) ? '1' : '0',
                ]);
                header('Location: ' . adaptorTabUrl($contratoPostId, 'localizacao', $termoBusca, $tipoBusca) . '&ok=contato');
                exit;
            }

            if ($acaoPost === 'adicionar_endereco') {
                $repository->adicionarEnderecoManual($pessoaPostId, [
                    'tipo' => (string) ($_POST['tipo'] ?? 'residencial'),
                    'cep' => (string) ($_POST['cep'] ?? ''),
                    'uf' => (string) ($_POST['uf'] ?? ''),
                    'cidade' => (string) ($_POST['cidade'] ?? ''),
                    'bairro' => (string) ($_POST['bairro'] ?? ''),
                    'logradouro' => (string) ($_POST['logradouro'] ?? ''),
                    'numero' => (string) ($_POST['numero'] ?? ''),
                    'complemento' => (string) ($_POST['complemento'] ?? ''),
                    'principal' => isset($_POST['principal']) ? '1' : '0',
                ]);
                header('Location: ' . adaptorTabUrl($contratoPostId, 'localizacao', $termoBusca, $tipoBusca) . '&ok=endereco');
                exit;
            }

            if ($acaoPost === 'atualizar_endereco') {
                $repository->atualizarEnderecoManual($pessoaPostId, adaptorInt($_POST['endereco_id'] ?? null), [
                    'tipo' => (string) ($_POST['tipo'] ?? 'residencial'),
                    'principal' => isset($_POST['principal']) ? '1' : '0',
                ]);
                header('Location: ' . adaptorTabUrl($contratoPostId, 'localizacao', $termoBusca, $tipoBusca) . '&ok=endereco');
                exit;
            }
        }

        if ($acaoPost === 'registrar_atendimento') {
            if (!usuarioPode('atendimentos.registrar')) {
                throw new RuntimeException('Seu perfil não possui permissão para registrar acionamento.');
            }

            $resultadoRef = (string) ($_POST['resultado_ref'] ?? '');
            $resultadosDisponiveisPost = $atendimentoRepository->listarResultadosAtivos();
            if ($resultadosDisponiveisPost !== []) {
                $resultadosDisponiveisPost = worksFiltrarResultadosContexto(
                    $resultadosDisponiveisPost,
                    $contratoPost,
                    (int) ($contratoPost['acordos_ativos'] ?? 0) > 0,
                    $credorConfiguracaoRepository
                );
                if (!worksResultadoRefPermitido($resultadoRef, $resultadosDisponiveisPost)) {
                    throw new RuntimeException('A tabulação selecionada não está disponível para este contrato, credor, produto ou perfil.');
                }
            }

            $dadosAtendimento = [
                'contrato_id' => $contratoPostId,
                'contato_id' => adaptorInt($_POST['contato_id'] ?? null) ?: null,
                'operador_nome' => usuarioAtualNome() ?: 'Operador',
                'usuario_id' => usuarioAtualId(),
                'canal' => (string) ($_POST['canal'] ?? 'ligacao'),
                'contato_utilizado' => trim((string) ($_POST['contato_utilizado'] ?? '')) ?: null,
                'observacao' => trim((string) ($_POST['observacao'] ?? '')) ?: null,
                'proximo_acionamento' => trim((string) ($_POST['proximo_acionamento'] ?? '')) ?: null,
                'motivo_inadimplencia_id' => adaptorInt($_POST['motivo_inadimplencia_id'] ?? null) ?: null,
                'estrategia_item_id' => $estrategiaItemId > 0 ? $estrategiaItemId : null,
            ];

            if (str_starts_with($resultadoRef, 'id:')) {
                $dadosAtendimento['resultado_id'] = (int) substr($resultadoRef, 3);
            } elseif (str_starts_with($resultadoRef, 'legacy:')) {
                $dadosAtendimento['resultado'] = substr($resultadoRef, 7);
            } else {
                $dadosAtendimento['resultado'] = 'outro';
            }

            $atendimentoId = $atendimentoRepository->registrar(
                (int) $contratoPost['pessoa_id'],
                $dadosAtendimento
            );
            $estrategiaSyncErro = false;
            if ($estrategiaItemId > 0) {
                try {
                    $estrategiaRepository->marcarTrabalhado(
                        $estrategiaItemId,
                        (int) $contratoPost['pessoa_id'],
                        $contratoPostId,
                        $atendimentoId,
                        usuarioAtualId() ?? 0
                    );
                    registrarAuditoriaSistema(
                        'estrategia.contrato_trabalhado',
                        'estrategia_contrato',
                        $estrategiaItemId,
                        'Contrato da estratégia concluído após registro no Pactua.',
                        null,
                        ['contrato_id' => $contratoPostId, 'atendimento_id' => $atendimentoId]
                    );
                } catch (Throwable $e) {
                    $estrategiaSyncErro = true;
                    registrarAuditoriaSistema(
                        'estrategia.sincronizacao_erro',
                        'estrategia_contrato',
                        $estrategiaItemId,
                        'O atendimento foi salvo, mas o item da estratégia não pôde ser concluído.',
                        null,
                        ['contrato_id' => $contratoPostId, 'atendimento_id' => $atendimentoId, 'erro' => $e->getMessage()]
                    );
                }
            }
            header('Location: ' . adaptorTabUrl($contratoPostId, 'acionamento', $termoBusca, $tipoBusca)
                . '&ok=acionamento'
                . ($estrategiaSyncErro ? '&estrategia_sync=erro' : ''));
            exit;
        }

        if ($acaoPost === 'registrar_acordo') {
            if (!usuarioPode('acordos.criar')) {
                throw new RuntimeException('Seu perfil não possui permissão para criar acordo.');
            }
            if (adaptorContratoRetiradoCredor($contratoPost)) {
                throw new RuntimeException('Contrato retirado pelo credor. Não é permitido registrar nova promessa/acordo.');
            }

            $perfilFinanceiro = adaptorFinanceiroPerfil($contratoPost, $credorConfiguracaoRepository);
            $propostaDescricao = trim((string) ($_POST['proposta_descricao'] ?? ''));
            $quantidadeParcelas = filter_var($_POST['quantidade_parcelas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
            if ($quantidadeParcelas === false) {
                $quantidadeParcelas = adaptorParcelasPropostaDescricao($propostaDescricao);
            }
            $valorDescricao = adaptorValorPropostaDescricao($propostaDescricao);
            $valorAcordo = adaptorDecimalEntrada($_POST['valor_acordo'] ?? null)
                ?? ($valorDescricao !== null ? round($valorDescricao * (int) $quantidadeParcelas, 2) : null)
                ?? (float) ($contratoPost['saldo_atual'] ?? 0);
            if ($valorAcordo <= 0) {
                throw new RuntimeException('Valor da promessa inválido. Selecione uma proposta retornada pelo credor.');
            }

            $primeiroVencimentoIso = trim((string) ($_POST['primeiro_vencimento_iso'] ?? ''));
            $dataPrimeiraParcela = adaptorDataIsoParaDate($primeiroVencimentoIso ?: ($_POST['data_primeira_parcela'] ?? ''), $proximaParcelaPadrao);
            $tipoPagamentoTag = trim((string) ($_POST['tipo_pagamento_tag'] ?? ''));
            $formaPagamento = adaptorFormaPagamentoCredor($tipoPagamentoTag);
            $emailPromessa = trim((string) ($_POST['email_promessa'] ?? ''));
            $telefonePromessa = trim((string) ($_POST['telefone_promessa'] ?? ''));
            $canalAcionamento = (string) ($_POST['canal_acionamento'] ?? 'ligacao');
            if (!array_key_exists($canalAcionamento, AtendimentoRepository::CANAIS)) {
                $canalAcionamento = 'ligacao';
            }

            $fechadoPorId = usuarioAtualId() ?? 0;
            $fechadoPorNome = usuarioAtualNome() ?: 'Operador';
            $responsavelSolicitadoId = filter_var($_POST['operador_responsavel_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
            $responsavelAcordo = ['id' => $fechadoPorId, 'nome' => $fechadoPorNome];
            if ($podeAtribuirAcordo) {
                $responsavelAcordo = worksResolverResponsavelAcordo($usuariosAtribuiveisAcordo, $responsavelSolicitadoId, $fechadoPorId, $fechadoPorNome);
            }

            $integracaoCredorExecutada = false;
            $integracaoCredorStatus = 'nao_enviado';
            $integracaoCredorMensagem = 'Proposta apenas registrada internamente. Integração do credor não executada para este perfil financeiro.';
            $integracaoCredorResposta = null;
            $integracaoCredorPayload = null;

            if ($perfilFinanceiro === 'acme') {
                $cacheAcme = $repository->obterFinanceiroAcmeCache($contratoPostId);
                $ownerDescription = trim((string) ($cacheAcme['owner_description'] ?? ''));
                if ($ownerDescription === '') {
                    throw new RuntimeException('OwnerDescription ACME não encontrado. Reabra o contrato pela busca para atualizar as propostas antes de concluir.');
                }

                $integracaoCredorPayload = [
                    'UserID' => 0,
                    'DocNumber' => preg_replace('/\D+/', '', (string) ($contratoPost['pessoa_documento'] ?? '')) ?? '',
                    'OwnerDescription' => $ownerDescription,
                    'Contract' => (string) ($contratoPost['codigo_contrato'] ?? ''),
                    'NegotiationID' => (int) ($_POST['proposta_id'] ?? 0),
                    'PhoneID' => 0,
                    'InstallmentDate' => $primeiroVencimentoIso,
                ];

                try {
                    $adapterCredor = $credorAdapterFactory->paraContrato($contratoPost);
                    if ($adapterCredor === null || !$adapterCredor->configurado()) {
                        throw new RuntimeException('Adapter ACME não configurado para este contrato.');
                    }
                    $integracaoCredorResposta = $adapterCredor->criarAcordo($integracaoCredorPayload);
                    $integracaoCredorExecutada = true;
                    $integracaoCredorStatus = 'enviado';
                    $integracaoCredorMensagem = 'Proposta enviada para a ACME com sucesso.';
                } catch (Throwable $e) {
                    throw new RuntimeException('Não foi possível salvar a proposta na ACME: ' . $e->getMessage());
                }
            }

            $payloadFinanceiro = [
                'financeiro_perfil' => $perfilFinanceiro,
                'fluxo' => 'promessa_pagamento_api_credor_preparada',
                'credor_api' => $perfilFinanceiro === 'acme' ? 'ACME' : null,
                'proposta' => [
                    'id' => trim((string) ($_POST['proposta_id'] ?? '')),
                    'descricao' => $propostaDescricao,
                    'valor_estimado' => $valorAcordo,
                    'quantidade_parcelas_estimadas' => (int) $quantidadeParcelas,
                ],
                'primeiro_vencimento' => [
                    'iso_original' => $primeiroVencimentoIso,
                    'data' => $dataPrimeiraParcela,
                ],
                'tipo_acordo' => [
                    'id' => trim((string) ($_POST['tipo_acordo_id'] ?? '')),
                    'descricao' => trim((string) ($_POST['tipo_acordo_descricao'] ?? '')),
                ],
                'tipo_pagamento' => [
                    'tag' => $tipoPagamentoTag,
                    'descricao' => trim((string) ($_POST['tipo_pagamento_descricao'] ?? '')),
                ],
                'forma_recebimento_boleto' => [
                    'tag' => trim((string) ($_POST['forma_recebimento_tag'] ?? '')),
                    'descricao' => trim((string) ($_POST['forma_recebimento_descricao'] ?? '')),
                ],
                'contato_promessa' => [
                    'email' => $emailPromessa,
                    'telefone' => $telefonePromessa,
                    'canal_acionamento' => $canalAcionamento,
                ],
                'operador_fechamento' => [
                    'usuario_id' => $fechadoPorId > 0 ? $fechadoPorId : null,
                    'nome' => $fechadoPorNome,
                    'origem' => 'works_usuario_logado',
                ],
                'operador_responsavel' => [
                    'usuario_id' => (int) ($responsavelAcordo['id'] ?? 0) > 0 ? (int) $responsavelAcordo['id'] : null,
                    'nome' => (string) ($responsavelAcordo['nome'] ?? $fechadoPorNome),
                    'atribuido_por_usuario_id' => $fechadoPorId > 0 ? $fechadoPorId : null,
                    'atribuido_por_nome' => $fechadoPorNome,
                    'permissao_usada' => $podeAtribuirAcordo,
                ],
                'integracao_credor_executada' => $integracaoCredorExecutada,
                'integracao_credor_status' => $integracaoCredorStatus,
                'integracao_credor_mensagem' => $integracaoCredorMensagem,
                'integracao_credor_payload' => $integracaoCredorPayload,
                'integracao_credor_resposta' => $integracaoCredorResposta,
                'estrategia_item_id' => $estrategiaItemId > 0 ? $estrategiaItemId : null,
            ];

            $observacaoAcordo = trim((string) ($_POST['observacao'] ?? ''));
            $observacaoAcordo = trim("Promessa de pagamento. Proposta: {$propostaDescricao}. " . $observacaoAcordo);

            $acordoId = $acordoRepository->registrar((int) $contratoPost['pessoa_id'], [
                'contrato_id' => $contratoPostId,
                'valor_acordo' => number_format($valorAcordo, 2, ',', '.'),
                'valor_entrada' => null,
                'data_entrada' => null,
                'quantidade_parcelas' => (int) $quantidadeParcelas,
                'valor_parcela' => null,
                'data_primeira_parcela' => $dataPrimeiraParcela,
                'periodicidade' => ((int) $quantidadeParcelas) === 1 ? 'unica' : 'mensal',
                'forma_pagamento' => $formaPagamento,
                'status_operacional' => 'pendente',
                'status_integracao' => $integracaoCredorStatus,
                'observacao' => $observacaoAcordo,
                'operador_nome' => $fechadoPorNome,
                'origem' => 'manual',
                'origem_tela' => 'operacao/works.php',
                'payload_extra_adicional' => $payloadFinanceiro,
            ]);

            // Achado 2026-09-29 (relato da operação: "tem acordo e mesmo assim ta
            // simulando"): fechar a proposta na ACME não atualizava
            // contratos.payload_extra.acme_acordo - o mesmo cache que o
            // Financeiro usa pra decidir se esconde a simulação
            // (impede_nova_proposta). Sem isso, revisitar o Financeiro
            // dentro da janela de 10 min (mesma aba, sessionStorage não
            // força refresh) reaproveitava o cache de ANTES do fechamento
            // e deixava simular de novo em cima de um acordo recém-fechado.
            // Não crítico: o acordo já está criado no CRM e na ACME de
            // qualquer forma, e o Controle de Acordos/relatórios não
            // dependem deste cache específico - por isso não interrompe o
            // fluxo se falhar.
            if ($perfilFinanceiro === 'acme' && $integracaoCredorExecutada) {
                try {
                    $agreementPayloadAtualizado = $adapterCredor->consultarAcordo(
                        (string) ($contratoPost['pessoa_documento'] ?? ''),
                        (string) ($contratoPost['codigo_contrato'] ?? '')
                    );
                    $repository->atualizarContratoAcordoAcme($contratoPostId, $agreementPayloadAtualizado);
                } catch (Throwable $e) {
                    registrarAuditoriaSistema(
                        'acordo.cache_acme_pos_fechamento_erro',
                        'acordo_operacional',
                        $acordoId,
                        'Acordo fechado na ACME, mas a atualização do cache de acordo do contrato falhou (Financeiro pode continuar mostrando simulação até o próximo refresh manual).',
                        null,
                        ['contrato_id' => $contratoPostId, 'erro' => $e->getMessage()]
                    );
                }
            }

            $estrategiaSyncErro = false;
            $atendimentoPromessaId = 0;
            try {
                $resultadosPromessa = $atendimentoRepository->listarResultadosAtivos();
                $credorIdPromessa = filter_var($contratoPost['credor_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
                $resultadoPromessaId = adaptorResultadoPromessaId($resultadosPromessa, $credorIdPromessa);
                $contatoIdPromessa = adaptorContatoSelecionadoValor($repository->listarContatos((int) $contratoPost['pessoa_id']), $telefonePromessa ?: $emailPromessa);
                $dadosAtendimentoPromessa = [
                    'contrato_id' => $contratoPostId,
                    'contato_id' => $contatoIdPromessa,
                    'operador_nome' => $fechadoPorNome,
                    'usuario_id' => usuarioAtualId(),
                    'canal' => $canalAcionamento,
                    'contato_utilizado' => $telefonePromessa ?: ($emailPromessa ?: null),
                    'observacao' => 'Promessa de pagamento registrada no financeiro Pactua. Acordo operacional #' . $acordoId . '. Proposta: ' . $propostaDescricao,
                    'proximo_acionamento' => null,
                    'estrategia_item_id' => $estrategiaItemId > 0 ? $estrategiaItemId : null,
                ];
                if ($resultadoPromessaId !== null) {
                    $dadosAtendimentoPromessa['resultado_id'] = $resultadoPromessaId;
                } else {
                    $dadosAtendimentoPromessa['resultado'] = 'promessa_pagamento';
                }
                $atendimentoPromessaId = $atendimentoRepository->registrar(
                    (int) $contratoPost['pessoa_id'],
                    $dadosAtendimentoPromessa
                );
            } catch (Throwable $e) {
                // O acordo não deve ser perdido caso a tabulação automática falhe.
                $estrategiaSyncErro = $estrategiaItemId > 0;
                registrarAuditoriaSistema(
                    'works.tabulacao_acordo_erro',
                    'acordo_operacional',
                    $acordoId,
                    'O acordo foi salvo, mas a tabulação automática do Pactua falhou.',
                    null,
                    ['contrato_id' => $contratoPostId, 'erro' => $e->getMessage()]
                );
            }

            if ($estrategiaItemId > 0 && $atendimentoPromessaId > 0) {
                try {
                    $estrategiaRepository->marcarTrabalhado(
                        $estrategiaItemId,
                        (int) $contratoPost['pessoa_id'],
                        $contratoPostId,
                        $atendimentoPromessaId,
                        usuarioAtualId() ?? 0,
                        $acordoId
                    );
                    registrarAuditoriaSistema(
                        'estrategia.contrato_trabalhado',
                        'estrategia_contrato',
                        $estrategiaItemId,
                        'Contrato da estratégia concluído após acordo registrado no Pactua.',
                        null,
                        ['contrato_id' => $contratoPostId, 'atendimento_id' => $atendimentoPromessaId, 'acordo_id' => $acordoId]
                    );
                } catch (Throwable $e) {
                    $estrategiaSyncErro = true;
                    registrarAuditoriaSistema(
                        'estrategia.sincronizacao_erro',
                        'estrategia_contrato',
                        $estrategiaItemId,
                        'O acordo foi salvo, mas o item da estratégia não pôde ser concluído.',
                        null,
                        ['contrato_id' => $contratoPostId, 'atendimento_id' => $atendimentoPromessaId, 'acordo_id' => $acordoId, 'erro' => $e->getMessage()]
                    );
                }
            }

            header('Location: ' . adaptorTabUrl($contratoPostId, 'financeiro', $termoBusca, $tipoBusca)
                . '&ok=promessa&refresh_api=1'
                . ($estrategiaSyncErro ? '&estrategia_sync=erro' : ''));
            exit;
        }

        throw new RuntimeException('Ação operacional inválida.');
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        $contratoId = $contratoPostId ?? $contratoId;
        $tab = match ($acaoPost ?? '') {
            'registrar_acordo' => 'financeiro',
            'adicionar_contato', 'atualizar_contato', 'adicionar_endereco', 'atualizar_endereco' => 'localizacao',
            default => 'acionamento',
        };
    }
}

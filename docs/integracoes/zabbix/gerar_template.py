#!/usr/bin/env python3
"""Gera template-works-crm.yaml (Zabbix 6.0+) a partir das métricas de /metrics.php.

O TI da empresa usa Zabbix (não Prometheus) e o Grafana lê do Zabbix
(requisito de 2026-10-07). O Zabbix lê o mesmo /metrics.php (formato
Prometheus) com um item HTTP agent e separa cada métrica com o pré-processamento
"Prometheus pattern". Não precisa instalar agente na VM.

Os alertas são os mesmos 15 de ../grafana/alertas-crm.yml (+ bloco do coletor falhando).
Métrica nova no scripts/exportar-metricas.sh -> acrescentar aqui e rodar:
    python3 docs/integracoes/zabbix/gerar_template.py
"""
import hashlib
import os
import uuid

import yaml

T = 'Pactua CRM by HTTP'
GRUPO = 'Templates/Applications'


def uid(nome):
    """UUIDv4 fixo por nome (reimportar atualiza em vez de duplicar)."""
    return uuid.UUID(bytes=hashlib.md5(('works-crm:' + nome).encode()).digest(), version=4).hex


def padrao(metrica, funcao=None, padrao_falha=None):
    passo = {'type': 'PROMETHEUS_PATTERN', 'parameters': [metrica, 'function', funcao] if funcao else [metrica, 'value', '']}
    if padrao_falha is not None:
        passo['error_handler'] = 'CUSTOM_VALUE'
        passo['error_handler_params'] = padrao_falha
    return passo


# Converte "hora em que aconteceu" (epoch) em "há quantos segundos".
IDADE = {'type': 'JAVASCRIPT', 'parameters': ['return Math.floor(Date.now() / 1000) - Number(value);']}


def item(chave, nome, metrica, *, unidade='', tipo='FLOAT', valuemap=None, idade=False, componente='crm', triggers=(), descricao='', funcao=None, padrao_falha=None):
    i = {
        'uuid': uid('item:' + chave),
        'name': nome,
        'type': 'DEPENDENT',
        'key': chave,
        'delay': '0',
        'value_type': tipo,
        'description': descricao or f'Métrica {metrica} do /metrics.php.',
        'preprocessing': [padrao(metrica, funcao, padrao_falha)] + ([IDADE] if idade else []),
        'master_item': {'key': 'crm.metricas.get'},
        'tags': [{'tag': 'component', 'value': componente}],
    }
    if unidade:
        i['units'] = unidade
    if valuemap:
        i['valuemap'] = {'name': valuemap}
    if triggers:
        i['triggers'] = list(triggers)
    return i


def trigger(nome, expressao, prioridade, descricao, prototipo=False):
    return {
        'uuid': uid(('tp:' if prototipo else 't:') + nome),
        'expression': expressao,
        'name': nome,
        'priority': prioridade,
        'description': descricao,
        'tags': [{'tag': 'scope', 'value': 'availability'}],
    }


def k(chave):
    return f'/{T}/{chave}'


itens = [
    {
        'uuid': uid('item:crm.metricas.get'),
        'name': 'CRM: coleta do /metrics.php',
        'type': 'HTTP_AGENT',
        'key': 'crm.metricas.get',
        'delay': '1m',
        'history': '0',
        'trends': '0',
        'value_type': 'TEXT',
        'timeout': '15s',
        'url': '{$CRM.URL}',
        'headers': [{'name': 'Authorization', 'value': 'Bearer {$CRM.TOKEN}'}],
        'description': 'Item mestre: baixa todas as métricas do CRM (formato Prometheus) a cada minuto. Os outros itens são separados daqui.',
        'tags': [{'tag': 'component', 'value': 'raw'}],
    },
    item('crm.coletor.execucao', 'CRM: hora da última coleta na VM', 'crm_coletor_execucao_timestamp_segundos', unidade='unixtime', componente='coletor', triggers=[
        trigger('CRM fora do ar (sem resposta do /metrics.php há 3 min)', f'nodata({k("crm.coletor.execucao")},3m)=1', 'DISASTER',
                'A VM 10.0.0.74, o Apache/PHP ou a rede não respondem, ou o token foi recusado (401). Ver o erro do item "CRM: coleta do /metrics.php".'),
    ]),
    item('crm.metricas.idade', 'CRM: idade das métricas', 'crm_metricas_idade_segundos', unidade='s', componente='coletor', triggers=[
        trigger('CRM: coletor de métricas parado na VM', f'min({k("crm.metricas.idade")},5m)>{{$CRM.COLETOR.IDADE.MAX}}', 'HIGH',
                'O cron do scripts/exportar-metricas.sh parou. Os outros alertas ficam cegos.'),
    ]),
    item('crm.coletor.duracao', 'CRM: duração da coleta', 'crm_coletor_duracao_segundos', unidade='s', componente='coletor'),
    item('crm.debezium.conector', 'CDC: conector Debezium', 'crm_debezium_conector_rodando', tipo='UNSIGNED', valuemap='CRM rodando', componente='cdc'),
    item('crm.debezium.task', 'CDC: task do Debezium', 'crm_debezium_task_rodando', tipo='UNSIGNED', valuemap='CRM rodando', componente='cdc'),
    item('crm.debezium.offset.idade', 'CDC: idade do último evento lido do MySQL', 'crm_debezium_offset_timestamp_segundos', unidade='s', idade=True, componente='cdc'),
    item('crm.cdc.lag', 'CDC: mensagens ainda não projetadas', 'crm_cdc_lag_mensagens', tipo='UNSIGNED', componente='cdc', triggers=[
        trigger('CDC: fila com mais de {$CRM.CDC.LAG.MAX} mensagens e não diminui há 1h',
                f'min({k("crm.cdc.lag")},1h)>{{$CRM.CDC.LAG.MAX}} and last({k("crm.cdc.lag")})>=first({k("crm.cdc.lag")},1h)', 'AVERAGE',
                'Worker do CDC travado? OpenSearch e dashboards atrasados.'),
    ]),
    item('crm.opensearch.status', 'OpenSearch: status', 'crm_opensearch_status', tipo='UNSIGNED', valuemap='CRM OpenSearch status', componente='opensearch', triggers=[
        trigger('OpenSearch amarelo/vermelho há 15 min', f'max({k("crm.opensearch.status")},15m)<2', 'AVERAGE', 'Busca e dashboards do CRM podem falhar.'),
    ]),
    item('crm.opensearch.contratos', 'OpenSearch: contratos indexados', 'crm_opensearch_documentos{indice="crm_contratos_v1"}', tipo='UNSIGNED', componente='opensearch'),
    item('crm.mysql.rodando', 'MySQL: respondendo', 'crm_mysql_rodando', tipo='UNSIGNED', valuemap='CRM rodando', componente='mysql', padrao_falha='0'),
    item('crm.mysql.conexoes', 'MySQL: conexões abertas', 'crm_mysql_conexoes', tipo='UNSIGNED', componente='mysql'),
    item('crm.mysql.rss', 'MySQL: memória RAM', 'crm_mysql_memoria_rss_bytes', unidade='B', componente='mysql'),
    item('crm.mysql.swap', 'MySQL: memória em swap', 'crm_mysql_swap_bytes', unidade='B', componente='mysql', triggers=[
        trigger('MySQL com mais de {$CRM.MYSQL.SWAP.MAX} em swap há 30 min', f'min({k("crm.mysql.swap")},30m)>{{$CRM.MYSQL.SWAP.MAX}}', 'WARNING',
                'MySQL lento. Paliativo: reiniciar o MySQL fora do expediente e depois a task do Debezium.'),
    ]),
    item('crm.mysql.contratos', 'MySQL: contratos', 'crm_mysql_registros{entidade="contratos"}', tipo='UNSIGNED', componente='mysql'),
    item('crm.mysql.pessoas', 'MySQL: pessoas', 'crm_mysql_registros{entidade="pessoas"}', tipo='UNSIGNED', componente='mysql'),
    item('crm.mysql.acordos', 'MySQL: acordos', 'crm_mysql_registros{entidade="acordos"}', tipo='UNSIGNED', componente='mysql'),
    item('crm.host.disco', 'VM: uso do disco', 'crm_host_disco_uso_percentual', unidade='%', componente='vm', triggers=[
        trigger('VM: disco acima de {$CRM.DISCO.MAX}%', f'min({k("crm.host.disco")},10m)>{{$CRM.DISCO.MAX}}', 'HIGH', 'Disco da VM do CRM enchendo (binlog, backups, staging).'),
    ]),
    item('crm.host.memoria', 'VM: memória disponível', 'crm_host_memoria_disponivel_bytes', unidade='B', componente='vm'),
    item('crm.host.swap', 'VM: swap usado', 'crm_host_swap_usado_bytes', unidade='B', componente='vm'),
    item('crm.host.load1', 'VM: carga (load 1 min)', 'crm_host_load1', componente='vm'),
    item('crm.backup.idade', 'Backup: idade do último backup do MySQL', 'crm_backup_ultimo_timestamp_segundos', unidade='s', idade=True, componente='backup', triggers=[
        trigger('Backup do MySQL atrasado (mais de {$CRM.BACKUP.ATRASO.MAX})', f'min({k("crm.backup.idade")},10m)>{{$CRM.BACKUP.ATRASO.MAX}}', 'HIGH',
                'O backup diário das 03:30 não rodou. Ver /var/log/backup-mysql.log na VM.'),
    ]),
    item('crm.backup.tamanho', 'Backup: tamanho do último', 'crm_backup_ultimo_tamanho_bytes', unidade='B', componente='backup'),
    item('crm.carga.idade', 'Carga ACME: idade da última carga aplicada', 'crm_carga_ultima_aplicada_timestamp_segundos', unidade='s', idade=True, componente='carga', triggers=[
        trigger('Carga diária da ACME não aplicada (seg-sáb, depois das 9h)',
                f'min({k("crm.carga.idade")},15m)>{{$CRM.CARGA.ATRASO.MAX}} and dayofweek()<>7 and time()>=090000', 'HIGH',
                'A carga diária da ACME (06h) não foi aplicada. Usa o fuso do servidor Zabbix (deve ser horário de Brasília).'),
    ]),
    item('crm.acionamentos.hoje', 'Operação: acionamentos hoje', 'crm_acionamentos_hoje', tipo='UNSIGNED', componente='operacao'),
    item('crm.acordos.hoje', 'Operação: acordos criados hoje', 'crm_acordos_criados_hoje', tipo='UNSIGNED', componente='operacao'),
    {
        'uuid': uid('item:crm.opensearch.diferenca'),
        'name': 'OpenSearch: diferença de contratos para o MySQL',
        'type': 'CALCULATED',
        'key': 'crm.opensearch.diferenca',
        'delay': '1m',
        'value_type': 'FLOAT',
        'params': 'abs(last(//crm.mysql.contratos)-last(//crm.opensearch.contratos))',
        'description': 'Contratos no MySQL menos contratos no índice do OpenSearch (0 = sincronizado).',
        'tags': [{'tag': 'component', 'value': 'opensearch'}],
        'triggers': [
            trigger('OpenSearch desincronizado do MySQL há 2h (mais de {$CRM.OPENSEARCH.DIFERENCA.MAX} contratos)',
                    f'min({k("crm.opensearch.diferenca")},2h)>{{$CRM.OPENSEARCH.DIFERENCA.MAX}}', 'AVERAGE',
                    'O CDC não está levando as mudanças do MySQL para o OpenSearch.'),
        ],
    },
]


def lld(chave, nome, metrica, macro, rotulo, prototipos, triggers=(), js_unicos=False, descricao=''):
    passos = [{'type': 'PROMETHEUS_TO_JSON', 'parameters': [metrica]}]
    regra = {
        'uuid': uid('lld:' + chave),
        'name': nome,
        'type': 'DEPENDENT',
        'key': chave,
        'delay': '0',
        'lifetime': '7d',
        'description': descricao,
        'item_prototypes': prototipos,
        'master_item': {'key': 'crm.metricas.get'},
        'preprocessing': passos,
    }
    if js_unicos:
        # Uma linha por valor do rótulo (a métrica tem 1 série por status).
        passos.append({'type': 'JAVASCRIPT', 'parameters': [
            'var vistos = {}, saida = [];\n'
            'JSON.parse(value).forEach(function (linha) {\n'
            f'    var v = linha.labels["{rotulo}"];\n'
            '    if (v !== undefined && !vistos[v]) { vistos[v] = true; var o = {}; o["' + macro + '"] = v; saida.push(o); }\n'
            '});\n'
            'return JSON.stringify(saida);'
        ]})
    else:
        regra['lld_macro_paths'] = [{'lld_macro': macro, 'path': f'$.labels.{rotulo}'}]
    if triggers:
        regra['trigger_prototypes'] = list(triggers)
    return regra


def prototipo(chave, nome, metrica, *, unidade='', tipo='FLOAT', valuemap=None, idade=False, componente='crm', triggers=(), funcao=None, padrao_falha=None):
    p = item(chave, nome, metrica, unidade=unidade, tipo=tipo, valuemap=valuemap, idade=idade, componente=componente, funcao=funcao, padrao_falha=padrao_falha)
    p['uuid'] = uid('proto:' + chave)
    if triggers:
        p['trigger_prototypes'] = [dict(t, uuid=uid('tp:' + t['name'])) for t in triggers]
    return p


descobertas = [
    lld('crm.containers.descoberta', 'Containers do CRM', 'crm_container_rodando', '{#CONTAINER}', 'container', [
        prototipo('crm.container.rodando[{#CONTAINER}]', 'Container {#CONTAINER}: rodando', 'crm_container_rodando{container="{#CONTAINER}"}', tipo='UNSIGNED', valuemap='CRM rodando', componente='containers', triggers=[
            trigger('Container {#CONTAINER} parado há 5 min', f'max({k("crm.container.rodando[{#CONTAINER}]")},5m)=0', 'HIGH', 'Container do CRM parado na VM 10.0.0.74.'),
        ]),
        prototipo('crm.container.reinicios[{#CONTAINER}]', 'Container {#CONTAINER}: reinícios', 'crm_container_reinicios{container="{#CONTAINER}"}', tipo='UNSIGNED', componente='containers'),
    ]),
    lld('crm.containers.saude.descoberta', 'Containers do CRM com healthcheck', 'crm_container_saudavel', '{#CONTAINER}', 'container', [
        prototipo('crm.container.saudavel[{#CONTAINER}]', 'Container {#CONTAINER}: saudável', 'crm_container_saudavel{container="{#CONTAINER}"}', tipo='UNSIGNED', valuemap='CRM saudável', componente='containers', triggers=[
            trigger('Container {#CONTAINER} com healthcheck falhando há 10 min', f'max({k("crm.container.saudavel[{#CONTAINER}]")},10m)=0', 'AVERAGE', 'O container está de pé mas não responde direito.'),
        ]),
    ]),
    lld('crm.integracoes.descoberta', 'Integrações com credores (fila de saída)', 'crm_integracao_eventos', '{#INTEGRACAO}', 'integracao', [
        prototipo('crm.integracao.erro_definitivo[{#INTEGRACAO}]', 'Integração {#INTEGRACAO}: eventos com erro definitivo', 'crm_integracao_eventos{integracao="{#INTEGRACAO}",status="erro_definitivo"}', tipo='UNSIGNED', componente='integracoes', funcao='sum', padrao_falha='0', triggers=[
            trigger('Integração {#INTEGRACAO}: evento com erro definitivo', f'min({k("crm.integracao.erro_definitivo[{#INTEGRACAO}]")},5m)>0', 'HIGH',
                    'Um envio para o credor desistiu depois de todas as tentativas. Ver Configurações > Integrações no CRM.'),
        ]),
        prototipo('crm.integracao.fila[{#INTEGRACAO}]', 'Integração {#INTEGRACAO}: eventos aguardando envio', 'crm_integracao_eventos{integracao="{#INTEGRACAO}",status=~"pendente|erro_retry"}', tipo='UNSIGNED', componente='integracoes', funcao='sum', padrao_falha='0', triggers=[
            trigger('Integração {#INTEGRACAO}: fila acumulando há 30 min (mais de {$CRM.INTEGRACAO.FILA.MAX})', f'min({k("crm.integracao.fila[{#INTEGRACAO}]")},30m)>{{$CRM.INTEGRACAO.FILA.MAX}}', 'AVERAGE',
                    'O credor não está aceitando os envios ou o worker de integração parou.'),
        ]),
        prototipo('crm.integracao.enviados[{#INTEGRACAO}]', 'Integração {#INTEGRACAO}: eventos enviados (total)', 'crm_integracao_eventos{integracao="{#INTEGRACAO}",status="enviado"}', tipo='UNSIGNED', componente='integracoes', funcao='sum', padrao_falha='0'),
    ], js_unicos=True),
    lld('crm.rotinas.descoberta', 'Rotinas agendadas (cron) da VM', 'crm_rotina_ultimo_log_timestamp_segundos', '{#ROTINA}', 'rotina', [
        prototipo('crm.rotina.idade[{#ROTINA}]', 'Rotina {#ROTINA}: tempo desde a última execução', 'crm_rotina_ultimo_log_timestamp_segundos{rotina="{#ROTINA}"}', unidade='s', idade=True, componente='rotinas', triggers=[
            trigger('Rotina {#ROTINA} parada (sem rodar há mais de {$CRM.ROTINA.ATRASO.MAX:"{#ROTINA}"})',
                    f'{{$CRM.ROTINA.ATRASO.MAX:"{{#ROTINA}}"}}>0 and min({k("crm.rotina.idade[{#ROTINA}]")},10m)>{{$CRM.ROTINA.ATRASO.MAX:"{{#ROTINA}}"}}', 'AVERAGE',
                    'Cron da VM parado para esta rotina. Só vigia as rotinas com macro {$CRM.ROTINA.ATRASO.MAX:"nome"} maior que 0.'),
        ]),
    ]),
    lld('crm.coletor.blocos.descoberta', 'Partes da coleta na VM', 'crm_coletor_bloco_ok', '{#BLOCO}', 'bloco', [
        prototipo('crm.coletor.bloco[{#BLOCO}]', 'Coleta {#BLOCO}: funcionando', 'crm_coletor_bloco_ok{bloco="{#BLOCO}"}', tipo='UNSIGNED', valuemap='CRM ok', componente='coletor', triggers=[
            trigger('CRM: parte "{#BLOCO}" da coleta falhando há 10 min', f'max({k("crm.coletor.bloco[{#BLOCO}]")},10m)=0', 'WARNING',
                    'O coletor da VM não conseguiu ler esta parte (as métricas dela ficam velhas). Ver storage/logs/exportar-metricas.log.'),
        ]),
    ]),
    lld('crm.opensearch.indices.descoberta', 'Índices do OpenSearch', 'crm_opensearch_documentos', '{#INDICE}', 'indice', [
        prototipo('crm.opensearch.documentos[{#INDICE}]', 'OpenSearch {#INDICE}: documentos', 'crm_opensearch_documentos{indice="{#INDICE}"}', tipo='UNSIGNED', componente='opensearch'),
    ]),
]

macros = [
    ('{$CRM.URL}', 'https://crm.exemplo.com.br/metrics.php', 'TEXT', 'Endereço das métricas (ou http://10.0.0.74:8080/metrics.php direto).'),
    ('{$CRM.TOKEN}', '', 'SECRET_TEXT', 'Token (conteúdo de /opt/crm-dev-docker/storage/metrics/token na VM). Preencher no host.'),
    ('{$CRM.COLETOR.IDADE.MAX}', '300', 'TEXT', 'Segundos sem coleta nova na VM para alertar.'),
    ('{$CRM.CDC.LAG.MAX}', '50000', 'TEXT', 'Mensagens do CDC na fila.'),
    ('{$CRM.DISCO.MAX}', '85', 'TEXT', 'Uso do disco da VM (%).'),
    ('{$CRM.MYSQL.SWAP.MAX}', '800M', 'TEXT', 'Memória do MySQL em swap.'),
    ('{$CRM.BACKUP.ATRASO.MAX}', '26h', 'TEXT', 'Tempo máximo desde o último backup do MySQL.'),
    ('{$CRM.CARGA.ATRASO.MAX}', '30h', 'TEXT', 'Tempo máximo desde a última carga da ACME aplicada.'),
    ('{$CRM.OPENSEARCH.DIFERENCA.MAX}', '100', 'TEXT', 'Contratos de diferença entre MySQL e OpenSearch.'),
    ('{$CRM.INTEGRACAO.FILA.MAX}', '20', 'TEXT', 'Eventos aguardando envio para o credor.'),
    ('{$CRM.ROTINA.ATRASO.MAX}', '0', 'TEXT', 'Rotinas sem contexto não são vigiadas (0).'),
    ('{$CRM.ROTINA.ATRASO.MAX:"executar-rotinas-carga"}', '1h', 'TEXT', 'Roda a cada 5 min.'),
    ('{$CRM.ROTINA.ATRASO.MAX:"purge-binlog-seguro"}', '1h', 'TEXT', 'Roda a cada 15 min.'),
    ('{$CRM.ROTINA.ATRASO.MAX:"verificar-disco"}', '1h', 'TEXT', 'Roda periodicamente.'),
]

valuemaps = [
    ('CRM rodando', [('0', 'parado'), ('1', 'rodando')]),
    ('CRM saudável', [('0', 'falhando'), ('1', 'saudável')]),
    ('CRM ok', [('0', 'falha'), ('1', 'ok')]),
    ('CRM OpenSearch status', [('0', 'vermelho'), ('1', 'amarelo'), ('2', 'verde')]),
]

template = {
    'zabbix_export': {
        'version': '6.0',
        'date': '2026-10-07T12:00:00Z',
        'groups': [{'uuid': uid('grupo:' + GRUPO), 'name': GRUPO}],
        'templates': [{
            'uuid': uid('template'),
            'template': T,
            'name': T,
            'description': 'Pactua CRM (VM 10.0.0.74): lê /metrics.php (formato Prometheus) com token, sem agente na VM. '
                           'Vincular a um host (ex.: "Pactua CRM") e preencher a macro {$CRM.TOKEN} no host. '
                           'Fonte: docs/integracoes/zabbix no repositório do CRM.',
            'groups': [{'name': GRUPO}],
            'items': itens,
            'discovery_rules': descobertas,
            'tags': [{'tag': 'class', 'value': 'application'}, {'tag': 'target', 'value': 'works-crm'}],
            'macros': [dict({'macro': m, 'value': v, 'description': d}, **({'type': t} if t != 'TEXT' else {})) for m, v, t, d in macros],
            'valuemaps': [{'uuid': uid('vm:' + n), 'name': n, 'mappings': [{'value': a, 'newvalue': b} for a, b in m]} for n, m in valuemaps],
        }],
    }
}

destino = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'template-works-crm.yaml')
with open(destino, 'w', encoding='utf-8') as f:
    f.write(yaml.safe_dump(template, sort_keys=False, allow_unicode=True, default_flow_style=False, width=1000))
print('gerado:', destino)

# Mesma coisa em JSON (o Zabbix importa os dois; a página do TI só oferece download de .json).
import json
with open(destino[:-len('.yaml')] + '.json', 'w', encoding='utf-8') as f:
    json.dump(template, f, ensure_ascii=False, indent=2)
    f.write('\n')
print('gerado:', destino[:-len('.yaml')] + '.json')

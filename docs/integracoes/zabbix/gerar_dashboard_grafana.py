#!/usr/bin/env python3
"""Gera dashboard-works-crm-zabbix.json: painel do Grafana lendo do Zabbix.

Usa o plugin "Zabbix" do Grafana (alexanderzobnin-zabbix-app) e os itens do
template-works-crm.yaml (filtra pelo NOME do item). No Import, escolher o
datasource Zabbix na variável "ds"; o host padrão é "Pactua CRM".
    python3 docs/integracoes/zabbix/gerar_dashboard_grafana.py
"""
import json
import os

DS = {'type': 'alexanderzobnin-zabbix-datasource', 'uid': '${ds}'}
painel_id = 0


def alvo(item, ref='A'):
    return {
        'refId': ref,
        'datasource': DS,
        'queryType': '0',
        'group': {'filter': '/.*/'},
        'host': {'filter': '$host'},
        'application': {'filter': ''},
        'itemTag': {'filter': ''},
        'item': {'filter': item},
        'functions': [],
        'options': {'showDisabledItems': False, 'skipEmptyValues': False, 'disableDataAlignment': False},
    }


def painel(tipo, titulo, x, y, w, h, alvos, unidade='none', limites=None, mapas=None, opcoes=None, descricao=''):
    global painel_id
    painel_id += 1
    passos = [{'color': 'green', 'value': None}] + [{'color': c, 'value': v} for v, c in (limites or [])]
    return {
        'id': painel_id,
        'type': tipo,
        'title': titulo,
        'description': descricao,
        'datasource': DS,
        'gridPos': {'x': x, 'y': y, 'w': w, 'h': h},
        'targets': alvos,
        'fieldConfig': {
            'defaults': {
                'unit': unidade,
                'thresholds': {'mode': 'absolute', 'steps': passos},
                'mappings': mapas or [],
                'color': {'mode': 'thresholds'} if tipo == 'stat' else {'mode': 'palette-classic'},
            },
            'overrides': [],
        },
        'options': opcoes or ({'reduceOptions': {'calcs': ['lastNotNull'], 'fields': '', 'values': False}, 'colorMode': 'background', 'graphMode': 'none', 'textMode': 'auto', 'justifyMode': 'auto'}
                              if tipo == 'stat' else {'legend': {'displayMode': 'list', 'placement': 'bottom', 'showLegend': True}, 'tooltip': {'mode': 'multi', 'sort': 'none'}}),
    }


def mapa(valores):
    return [{'type': 'value', 'options': {str(v): {'text': t, 'color': c, 'index': i} for i, (v, t, c) in enumerate(valores)}}]


RODANDO = mapa([(0, 'parado', 'red'), (1, 'rodando', 'green')])
HORA = 3600
paineis = [
    painel('stat', 'CRM no ar (idade das métricas)', 0, 0, 4, 4, [alvo('CRM: idade das métricas')], 's', [(180, 'orange'), (300, 'red')],
           descricao='Segundos desde a última coleta na VM. Sem dado = CRM fora do ar.'),
    painel('stat', 'CDC: fila', 4, 0, 4, 4, [alvo('CDC: mensagens ainda não projetadas')], 'short', [(10000, 'orange'), (50000, 'red')]),
    painel('stat', 'OpenSearch', 8, 0, 4, 4, [alvo('OpenSearch: status')], 'none', [(0, 'red'), (1, 'orange'), (2, 'green')],
           mapa([(0, 'vermelho', 'red'), (1, 'amarelo', 'orange'), (2, 'verde', 'green')])),
    painel('stat', 'Disco da VM', 12, 0, 4, 4, [alvo('VM: uso do disco')], 'percent', [(75, 'orange'), (85, 'red')]),
    painel('stat', 'Último backup', 16, 0, 4, 4, [alvo('Backup: idade do último backup do MySQL')], 's', [(25 * HORA, 'orange'), (26 * HORA, 'red')]),
    painel('stat', 'Última carga ACME', 20, 0, 4, 4, [alvo('Carga ACME: idade da última carga aplicada')], 's', [(28 * HORA, 'orange'), (30 * HORA, 'red')]),

    painel('stat', 'Acionamentos hoje', 0, 4, 4, 4, [alvo('Operação: acionamentos hoje')], 'short', opcoes=None),
    painel('stat', 'Acordos criados hoje', 4, 4, 4, 4, [alvo('Operação: acordos criados hoje')], 'short'),
    painel('stat', 'Debezium (CDC)', 8, 4, 4, 4, [alvo('CDC: task do Debezium')], 'none', [(0, 'red'), (1, 'green')], RODANDO),
    painel('stat', 'MySQL em swap', 12, 4, 4, 4, [alvo('MySQL: memória em swap')], 'bytes', [(500 * 1024 ** 2, 'orange'), (800 * 1024 ** 2, 'red')]),
    painel('stat', 'Contratos MySQL x OpenSearch', 16, 4, 4, 4, [alvo('OpenSearch: diferença de contratos para o MySQL')], 'short', [(1, 'orange'), (100, 'red')],
           descricao='0 = sincronizado.'),
    painel('stat', 'Erros definitivos no envio ao credor', 20, 4, 4, 4, [alvo('/^Integração .*: eventos com erro definitivo$/')], 'short', [(1, 'red')]),

    painel('stat', 'Containers', 0, 8, 24, 4, [alvo('/^Container .*: rodando$/')], 'none', [(0, 'red'), (1, 'green')], RODANDO,
           opcoes={'reduceOptions': {'calcs': ['lastNotNull'], 'fields': '', 'values': False}, 'colorMode': 'background', 'graphMode': 'none', 'textMode': 'value_and_name', 'justifyMode': 'auto', 'orientation': 'vertical'}),

    painel('timeseries', 'CDC: mensagens na fila', 0, 12, 12, 8, [alvo('CDC: mensagens ainda não projetadas')], 'short'),
    painel('timeseries', 'Envio para credores: aguardando', 12, 12, 12, 8, [alvo('/^Integração .*: eventos aguardando envio$/')], 'short'),
    painel('timeseries', 'MySQL: memória', 0, 20, 12, 8, [alvo('MySQL: memória RAM'), alvo('MySQL: memória em swap', 'B')], 'bytes'),
    painel('timeseries', 'VM: memória livre e swap', 12, 20, 12, 8, [alvo('VM: memória disponível'), alvo('VM: swap usado', 'B')], 'bytes'),
    painel('timeseries', 'VM: disco e carga', 0, 28, 12, 8, [alvo('VM: uso do disco'), alvo('VM: carga (load 1 min)', 'B')], 'short'),
    painel('timeseries', 'Operação do dia', 12, 28, 12, 8, [alvo('Operação: acionamentos hoje'), alvo('Operação: acordos criados hoje', 'B')], 'short'),
]

# Alertas ativos do host (painel "Problems" do plugin; a consulta roda no navegador).
painel_id += 1
paineis.insert(13, {
    'id': painel_id,
    'type': 'alexanderzobnin-zabbix-triggers-panel',
    'title': 'Alertas ativos',
    'datasource': DS,
    'gridPos': {'x': 0, 'y': 12, 'w': 24, 'h': 7},
    'targets': [{
        'refId': 'A', 'datasource': DS, 'queryType': '5',
        'group': {'filter': '/.*/'}, 'host': {'filter': '$host'}, 'application': {'filter': ''}, 'itemTag': {'filter': ''},
        'proxy': {'filter': ''}, 'trigger': {'filter': ''}, 'tags': {'filter': ''}, 'functions': [],
        'options': {'minSeverity': 0, 'acknowledged': 2, 'sortProblems': 'default', 'showDisabledItems': False, 'hostsInMaintenance': False, 'limit': 100},
    }],
    'options': {'layout': 'table', 'showTags': True, 'hostField': True, 'severityField': True, 'ageField': True, 'pageSize': 10},
})
for p in paineis[14:]:
    p['gridPos']['y'] += 7

dashboard = {
    'title': 'Pactua CRM',
    'uid': 'works-crm-zabbix',
    'description': 'Pactua CRM (VM 10.0.0.74) pelo Zabbix (template "Pactua CRM by HTTP"). Fonte: docs/integracoes/zabbix no repositório do CRM.',
    'tags': ['crm', 'works', 'zabbix'],
    'timezone': 'browser',
    'schemaVersion': 39,
    'refresh': '1m',
    'time': {'from': 'now-24h', 'to': 'now'},
    'editable': True,
    'templating': {'list': [
        {'name': 'ds', 'label': 'Zabbix', 'type': 'datasource', 'query': 'alexanderzobnin-zabbix-datasource', 'current': {}, 'hide': 0, 'refresh': 1, 'options': []},
        {'name': 'host', 'label': 'Host', 'type': 'textbox', 'query': 'Pactua CRM', 'current': {'text': 'Pactua CRM', 'value': 'Pactua CRM'}, 'hide': 0},
    ]},
    'panels': paineis,
}

destino = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'dashboard-works-crm-zabbix.json')
with open(destino, 'w', encoding='utf-8') as f:
    json.dump(dashboard, f, ensure_ascii=False, indent=2)
    f.write('\n')
print('gerado:', destino, '-', len(paineis), 'painéis')

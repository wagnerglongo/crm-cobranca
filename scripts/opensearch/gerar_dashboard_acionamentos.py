#!/usr/bin/env python3
"""Gera o ndjson do dashboard "ACME - Acionamentos (funil completo)".

Saída: docker/opensearch-dashboards/saved-objects/acme-acionamentos-funil.ndjson
Importar com scripts/opensearch/importar-dashboard-acionamentos.sh.
Fonte dos dados: índice crm_acionamentos_v1 (campos flag_*, funil_fluxo, lote,
motivo_inadimplencia, gerados por OpenSearchOperationalProjection::loadActions).
"""
import json, pathlib

IDX = "1e1c10a0-9a46-11f1-a634-1b05adb037ac"  # index-pattern crm_acionamentos_v1
IDX_ACORDOS = "0ee912e0-9a46-11f1-a634-1b05adb037ac"  # index-pattern crm_acordos_v1
BASE = "credor_codigo:ACME"
OUT = pathlib.Path(__file__).resolve().parents[2] / "docker/opensearch-dashboards/saved-objects/acme-acionamentos-funil.ndjson"
objs = []


def ss(query=BASE, idx=None):
    return json.dumps({"index": idx or IDX, "query": {"query": query, "language": "kuery"}, "filter": []})


def vis(vid, title, vtype, params, aggs, query=BASE, desc="", idx=None):
    objs.append({
        "id": vid, "type": "visualization", "migrationVersion": {"visualization": "7.10.0"},
        "attributes": {
            "title": title, "description": desc, "uiStateJSON": "{}",
            "visState": json.dumps({"title": title, "type": vtype, "params": params, "aggs": aggs}),
            "kibanaSavedObjectMeta": {"searchSourceJSON": ss(query, idx)},
        },
        "references": [{"id": idx or IDX, "name": "kibanaSavedObjectMeta.searchSourceJSON.index", "type": "index-pattern"}],
    })


def total(i, field, label):
    return {"id": str(i), "enabled": True, "type": "sum", "schema": "metric", "params": {"field": field, "customLabel": label}}


def terms(i, field, size, label, schema="bucket", order_by="_key", order="asc"):
    return {"id": str(i), "enabled": True, "type": "terms", "schema": schema,
            "params": {"field": field, "size": size, "order": order, "orderBy": order_by,
                       "otherBucket": False, "missingBucket": False, "customLabel": label}}


FLAGS = [(1, "flag_tentativa", "Tentativas"), (2, "flag_alo", "Alô"), (3, "flag_cpc", "CPC"), (4, "flag_acordo_fechado", "Acordos")]
metricas = [total(i, f, l) for i, f, l in FLAGS]
tabela = {"perPage": 15, "showPartialRows": False, "showMetricsAtAllLevels": False,
          "sort": {"columnIndex": None, "direction": None}, "showTotal": True, "totalFunc": "sum", "percentageCol": ""}

# 1. Funil
ACION = BASE + " and tipo_registro:acionamento"
# Acordo sem acionamento de acordo no dia vem com tentativa/alo/cpc = true
# no próprio registro "acordo" - por isso as etapas não filtram tipo_registro.
etapas = [("1. Tentativas", BASE + " and tentativa:true"), ("2. Alô", BASE + " and alo:true"), ("3. CPC", BASE + " and cpc:true"), ("4. Acordo", BASE + " and tipo_registro:acordo")]
vis("acme-acion-funil", "ACME - Funil de acionamentos (tentativa → alô → CPC → acordo)", "histogram",
    {"type": "histogram", "grid": {"categoryLines": False},
     "categoryAxes": [{"id": "CategoryAxis-1", "type": "category", "position": "bottom", "show": True, "style": {}, "scale": {"type": "linear"}, "labels": {"show": True, "filter": True, "truncate": 100}, "title": {}}],
     "valueAxes": [{"id": "ValueAxis-1", "name": "LeftAxis-1", "type": "value", "position": "left", "show": True, "style": {}, "scale": {"type": "linear", "mode": "normal"}, "labels": {"show": True, "rotate": 0, "filter": False, "truncate": 100}, "title": {"text": "Acionamentos"}}],
     "seriesParams": [{"show": True, "type": "histogram", "mode": "normal", "data": {"label": "Count", "id": "1"}, "valueAxis": "ValueAxis-1"}],
     "addTooltip": True, "addLegend": False, "legendPosition": "right", "times": [], "addTimeMarker": False, "labels": {"show": True}},
    [{"id": "1", "enabled": True, "type": "count", "schema": "metric", "params": {}},
     {"id": "2", "enabled": True, "type": "filters", "schema": "segment",
      "params": {"filters": [{"input": {"query": q, "language": "kuery"}, "label": l} for l, q in etapas]}}],
    desc="Alô = ligação atendida (tabulação com flag Alô); CPC = contato efetivo por ligação; acordo fechado (qualquer canal) sempre conta como tentativa, alô e CPC; Acordo = mesmo número do dashboard do CRM (fechado no CRM ou com responsável atribuído, pela data do acordo).")

# 2. KPIs
vis("acme-acion-kpis", "ACME - Totais do período", "metric",
    {"addTooltip": True, "addLegend": False, "type": "metric",
     "metric": {"percentageMode": False, "useRanges": False, "colorSchema": "Green to Red", "metricColorMode": "None",
                "colorsRange": [{"from": 0, "to": 10000}], "labels": {"show": True}, "invertColors": False,
                "style": {"bgFill": "#000", "bgColor": False, "labelColor": False, "subText": "", "fontSize": 36}}},
    metricas)

# 3. Fluxos
vis("acme-acion-fluxos", "ACME - Fluxos (alô / CPC / acordo)", "pie",
    {"type": "pie", "addTooltip": True, "addLegend": True, "legendPosition": "right", "isDonut": True,
     "labels": {"show": True, "values": True, "last_level": True, "truncate": 100}},
    [{"id": "1", "enabled": True, "type": "count", "schema": "metric", "params": {}},
     terms(2, "funil_fluxo", 10, "Fluxo", "segment", "1", "desc")],
    query=ACION,
    desc="Combinações: alo 1 cpc 1 acordo 0, alo 1 cpc 0 acordo 0, alo 0 cpc 0 acordo 0 etc. Clique numa fatia pra filtrar o painel (e a lista de detalhe).")

# 4. Motivos
vis("acme-acion-motivos", "ACME - Motivos da inadimplência", "pie",
    {"type": "pie", "addTooltip": True, "addLegend": True, "legendPosition": "right", "isDonut": False,
     "labels": {"show": True, "values": True, "last_level": True, "truncate": 100}},
    [{"id": "1", "enabled": True, "type": "count", "schema": "metric", "params": {}},
     terms(2, "motivo_inadimplencia", 20, "Motivo", "segment", "1", "desc")],
    query=BASE + " and motivo_inadimplencia_id:*",
    desc="Só acionamentos em que o operador informou o motivo (CPC sem sucesso e sem acordo ativo).")

# 5. Por operador
vis("acme-acion-operador", "ACME - Funil por operador", "table", tabela,
    metricas + [terms(5, "operador_nome", 100, "Operador", "bucket", "1", "desc")])

# 6. Motivo por operador
vis("acme-acion-motivo-operador", "ACME - Motivos por operador", "table", tabela,
    [{"id": "1", "enabled": True, "type": "count", "schema": "metric", "params": {"customLabel": "Qtd"}},
     terms(2, "operador_nome", 100, "Operador", "bucket", "1", "desc"),
     terms(3, "motivo_inadimplencia", 20, "Motivo", "bucket", "1", "desc")],
    query=BASE + " and motivo_inadimplencia_id:*")

# 7. Por lote
vis("acme-acion-lote", "ACME - Funil por lote", "table", tabela,
    metricas + [terms(5, "lote", 200, "Lote", "bucket", "1", "desc")])

# 7b. Acordos por responsável (índice de acordos)
vis("acme-acion-acordos-responsavel", "ACME - Acordos por responsável (CRM + atribuídos)", "table", tabela,
    [{"id": "1", "enabled": True, "type": "count", "schema": "metric", "params": {"customLabel": "Acordos"}},
     {"id": "2", "enabled": True, "type": "sum", "schema": "metric", "params": {"field": "valor_acordo", "customLabel": "Valor (R$)"}},
     terms(3, "responsavel_efetivo", 100, "Responsável", "bucket", "1", "desc")],
    query=BASE + " and contabiliza_dashboard:true", idx=IDX_ACORDOS,
    desc="Acordos fechados no CRM mais os que têm responsável atribuído em Controle de Acordos (ex.: exceção aprovada que veio por API/carga). Data = criação do acordo. Não obedece aos filtros de operador/lote do topo (índice de acordos).")

# 8. Evolução
vis("acme-acion-tempo", "ACME - Evolução diária do funil", "histogram",
    {"type": "histogram", "grid": {"categoryLines": False},
     "categoryAxes": [{"id": "CategoryAxis-1", "type": "category", "position": "bottom", "show": True, "style": {}, "scale": {"type": "linear"}, "labels": {"show": True, "filter": True, "truncate": 100}, "title": {}}],
     "valueAxes": [{"id": "ValueAxis-1", "name": "LeftAxis-1", "type": "value", "position": "left", "show": True, "style": {}, "scale": {"type": "linear", "mode": "normal"}, "labels": {"show": True, "rotate": 0, "filter": False, "truncate": 100}, "title": {"text": "Acionamentos"}}],
     "seriesParams": [{"show": True, "type": "line", "mode": "normal", "data": {"label": l, "id": str(i)}, "valueAxis": "ValueAxis-1", "drawLinesBetweenPoints": True, "showCircles": True, "interpolate": "linear", "lineWidth": 2} for i, f, l in FLAGS],
     "addTooltip": True, "addLegend": True, "legendPosition": "bottom", "times": [], "addTimeMarker": False, "labels": {"show": False}},
    metricas + [{"id": "5", "enabled": True, "type": "date_histogram", "schema": "segment",
                 "params": {"field": "criado_em", "useNormalizedOpenSearchInterval": True, "scaleMetricValues": False, "interval": "d",
                            "drop_partials": False, "min_doc_count": 1, "extended_bounds": {}}}])

# 9. Filtros (input controls)
def ctl(i, field, label):
    return {"id": str(i), "indexPattern": IDX, "fieldName": field, "parent": "", "label": label, "type": "list",
            "options": {"type": "terms", "multiselect": True, "size": 200, "order": "desc"}}

controles = [ctl(1, "lote", "Lote"), ctl(2, "operador_nome", "Operador"), ctl(3, "funil_fluxo", "Fluxo (alô/CPC/acordo)"),
             ctl(4, "canal", "Canal"), ctl(5, "motivo_inadimplencia", "Motivo")]
objs.append({
    "id": "acme-acion-filtros", "type": "visualization", "migrationVersion": {"visualization": "7.10.0"},
    "attributes": {"title": "ACME - Filtros do funil de acionamentos", "description": "", "uiStateJSON": "{}",
                   "visState": json.dumps({"title": "ACME - Filtros do funil de acionamentos", "type": "input_control_vis",
                                           "params": {"controls": controles, "updateFiltersOnChange": True, "useTimeFilter": True, "pinFilters": False}}),
                   "kibanaSavedObjectMeta": {"searchSourceJSON": json.dumps({"query": {"query": "", "language": "kuery"}, "filter": []})}},
    "references": [{"id": IDX, "name": f"control_{i}_index_pattern", "type": "index-pattern"} for i in range(len(controles))],
})

# 10. Lista de detalhe (saved search)
objs.append({
    "id": "acme-acion-detalhe", "type": "search", "migrationVersion": {"search": "7.9.3"},
    "attributes": {
        "title": "ACME - Acionamentos (lista de detalhe)", "description": "", "hits": 0,
        "columns": ["criado_em", "operador_nome", "lote", "cliente_nome", "codigo_contrato", "canal", "resultado_nome",
                    "motivo_inadimplencia", "funil_fluxo"],
        "sort": [["criado_em", "desc"]], "version": 1,
        "kibanaSavedObjectMeta": {"searchSourceJSON": json.dumps({"index": IDX, "query": {"query": ACION, "language": "kuery"}, "highlightAll": False, "version": True, "filter": []})},
    },
    "references": [{"id": IDX, "name": "kibanaSavedObjectMeta.searchSourceJSON.index", "type": "index-pattern"}],
})

# 11. Dashboard
layout = [  # (id, tipo, x, y, w, h)
    ("acme-acion-filtros", "visualization", 0, 0, 48, 8),
    ("acme-acion-kpis", "visualization", 0, 8, 48, 8),
    ("acme-acion-funil", "visualization", 0, 16, 28, 16),
    ("acme-acion-fluxos", "visualization", 28, 16, 20, 16),
    ("acme-acion-tempo", "visualization", 0, 32, 48, 14),
    ("acme-acion-operador", "visualization", 0, 46, 48, 16),
    ("acme-acion-acordos-responsavel", "visualization", 0, 62, 48, 14),
    ("acme-acion-motivos", "visualization", 0, 76, 20, 16),
    ("acme-acion-motivo-operador", "visualization", 20, 76, 28, 16),
    ("acme-acion-lote", "visualization", 0, 92, 48, 16),
    ("acme-acion-detalhe", "search", 0, 108, 48, 24),
]
panels = [{"version": "2.18.0", "gridData": {"x": x, "y": y, "w": w, "h": h, "i": str(n + 1)}, "panelIndex": str(n + 1),
           "embeddableConfig": {}, "panelRefName": f"panel_{n + 1}"} for n, (_, _, x, y, w, h) in enumerate(layout)]
objs.append({
    "id": "acme-acionamentos-funil", "type": "dashboard", "migrationVersion": {"dashboard": "7.9.3"},
    "attributes": {
        "title": "Acionamentos ACME - Funil completo",
        "description": "Tentativas, alô, CPC e acordos por operador e lote, motivos da inadimplência e lista de detalhe (exportável em CSV). Use os filtros do topo e o seletor de período.",
        "hits": 0, "version": 1, "timeRestore": True, "timeFrom": "now-30d", "timeTo": "now",
        "optionsJSON": json.dumps({"useMargins": True, "hidePanelTitles": False}),
        "panelsJSON": json.dumps(panels),
        "kibanaSavedObjectMeta": {"searchSourceJSON": json.dumps({"query": {"query": "", "language": "kuery"}, "filter": []})},
    },
    "references": [{"id": i, "name": f"panel_{n + 1}", "type": t} for n, (i, t, *_r) in enumerate(layout)],
})

OUT.write_text("\n".join(json.dumps(o, ensure_ascii=False) for o in objs) + "\n", encoding="utf-8")
print(f"{len(objs)} objetos -> {OUT}")

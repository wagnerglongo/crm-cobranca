ARG OPENSEARCH_VERSION=3.7.0
FROM opensearchproject/opensearch-dashboards:${OPENSEARCH_VERSION}

# O cluster DEV funciona sem o plugin Security. O Dashboards precisa seguir a
# mesma configuracao; apenas definir uma variavel de ambiente nao remove o
# plugin da imagem oficial.
RUN /usr/share/opensearch-dashboards/bin/opensearch-dashboards-plugin remove securityDashboards

FROM mysql:8.0

# Configuración por defecto: charset utf8mb4 y collation unicode_ci
COPY my.cnf /etc/mysql/conf.d/atlas.cnf
RUN chmod 644 /etc/mysql/conf.d/atlas.cnf

EXPOSE 3306

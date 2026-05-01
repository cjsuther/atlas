FROM nginx:1.27-alpine

RUN rm /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/atlas-front.conf

# Copiar el build estático (vanilla JS, sin compilación)
COPY src /usr/share/nginx/html

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]

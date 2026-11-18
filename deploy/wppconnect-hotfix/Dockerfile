# syntax=docker/dockerfile:1
# Hotfix pontual: manter o servidor 2.10.73 e o Core 2.3.6.
# O Core aceita @wppconnect/wa-js ^4.7.2; atualizar apenas para 4.8.2.
# Nao inclui tokens, perfis Chromium, segredos ou volumes na imagem.
FROM node:22.22.2-alpine AS wa-js-482
WORKDIR /tmp
RUN set -eux; \
    mkdir -p /tmp/pacote /tmp/wa-js; \
    npm pack '@wppconnect/wa-js@4.8.2' --pack-destination /tmp/pacote --silent; \
    tar -xzf /tmp/pacote/*.tgz -C /tmp/wa-js --strip-components=1; \
    node -e "const p=require('/tmp/wa-js/package.json'); if(p.version!=='4.8.2') throw Error('WA-JS inesperada')"

FROM wppconnect/wppconnect-server:2.10.73
LABEL org.opencontainers.image.title="WPPConnect Cardapim (WA-JS 4.8.2)"
LABEL org.opencontainers.image.description="Hotfix isolado da biblioteca de envio; mantem WPPConnect Server 2.10.73"
RUN rm -rf /usr/src/wpp-server/node_modules/@wppconnect/wa-js
COPY --from=wa-js-482 /tmp/wa-js/ /usr/src/wpp-server/node_modules/@wppconnect/wa-js/
RUN node -e "const fs=require('fs'); const dir='/usr/src/wpp-server/node_modules/@wppconnect/wa-js'; const p=JSON.parse(fs.readFileSync(dir+'/package.json','utf8')); if(p.version!=='4.8.2') throw Error('WA-JS incorreta'); const bundle=fs.readFileSync(dir+'/dist/wppconnect-wa.js','utf8'); if(!bundle.includes('getMaybeMeLidUser')) throw Error('Fallback LID ausente'); console.log('WA-JS validada: '+p.version)"

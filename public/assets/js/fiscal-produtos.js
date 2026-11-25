(() => {
    'use strict';
    const lote=document.querySelector('[data-fiscal-lote]');
    if (lote) {
        const linhas=[...lote.querySelectorAll('[data-fiscal-linha]')];
        const busca=lote.querySelector('[data-fiscal-busca]');
        const filtro=lote.querySelector('[data-fiscal-filtro]');
        const todos=lote.querySelector('[data-fiscal-todos]');
        const contagem=lote.querySelector('[data-fiscal-contagem]');
        const normalizar=v=>v.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
        const atualizar=()=>{
            const selecionados=linhas.filter(l=>l.querySelector('input').checked).length;
            const visiveis=linhas.filter(l=>!l.hidden);
            todos.checked=visiveis.length>0 && visiveis.every(l=>l.querySelector('input').checked);
            todos.indeterminate=!todos.checked && visiveis.some(l=>l.querySelector('input').checked);
            contagem.textContent=selecionados+' selecionados (máximo 200)';
        };
        const filtrar=()=>{
            for (const l of linhas) l.hidden=!(normalizar(l.dataset.nome).includes(normalizar(busca.value)) && (!filtro.value || l.dataset.categoria===filtro.value));
            atualizar();
        };
        busca.addEventListener('input',filtrar);
        filtro.addEventListener('change',filtrar);
        todos.addEventListener('change',()=>{for (const l of linhas) if (!l.hidden) l.querySelector('input').checked=todos.checked; atualizar();});
        lote.addEventListener('change',atualizar);
        atualizar();
    }
    for (const busca of document.querySelectorAll('[data-fiscal-filtrar]')) {
        const select=document.getElementById(busca.dataset.fiscalFiltrar);
        if (!select) continue;
        busca.addEventListener('input',()=>{
            const q=busca.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
            for (const op of select.options) op.hidden=!!op.value && !op.selected && !op.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(q);
        });
        busca.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();select.focus();}});
        select.addEventListener('change',()=>{busca.value='';for(const op of select.options)op.hidden=false;});
    }
    const form=document.querySelector('[data-fiscal-classificacao]');
    if (!form) return;
    const btn=form.querySelector('[data-ncm-pesquisar]');
    const busca=form.querySelector('[data-ncm-busca]');
    const status=form.querySelector('[data-ncm-status]');
    const resultados=form.querySelector('[data-ncm-resultados]');
    const ncm=form.elements.namedItem('ncm');
    btn.addEventListener('click',async()=>{
        if (busca.value.trim().length<2) { status.textContent='Digite pelo menos dois caracteres.'; return; }
        btn.disabled=true; status.textContent='Consultando BrasilAPI...'; resultados.replaceChildren();
        try {
            const r=await fetch(form.dataset.ncmUrl+'?q='+encodeURIComponent(busca.value.trim()),{headers:{Accept:'application/json'},credentials:'same-origin'});
            const d=await r.json();
            if (!r.ok || !d.ok) throw new Error(d.mensagem||'Consulta indisponível.');
            status.textContent=d.resultados.length?d.fonte+' — escolha somente a classificação compatível.':'Nenhum resultado. Tente outro termo ou código.';
            for (const item of d.resultados) {
                const b=document.createElement('button'); b.type='button'; b.className='btn btn-outline'; b.textContent=item.codigo+' — '+item.descricao+' (vigência: '+item.data_inicio+' a '+item.data_fim+')';
                b.addEventListener('click',()=>{ncm.value=item.codigo; ncm.dispatchEvent(new Event('input')); const conf=form.elements.namedItem('confirmado'); if(conf) conf.checked=false; const marcar=form.querySelector('input[name="campos[]"][value="ncm"]'); if (marcar) marcar.checked=true; status.textContent='NCM selecionado. Confira antes de salvar.'; resultados.replaceChildren();});
                resultados.append(b);
            }
        } catch(e) { status.textContent=e.message||'Consulta indisponível. Você pode salvar como pendente.'; }
        finally { btn.disabled=false; }
    });
    busca.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();btn.click();}});
    const conf=form.elements.namedItem('confirmado');
    const revisar=()=>{if(conf)conf.checked=false;};
    const cest=form.elements.namedItem('cest');
    const cestBusca=form.querySelector('[data-cest-busca]');
    const cestBotao=form.querySelector('[data-cest-pesquisar]');
    if(cestBotao && cestBusca && cest){
        const msg=form.querySelector('[data-cest-status]'),out=form.querySelector('[data-cest-resultados]');
        let consulta=0;
        const invalidar=()=>{consulta++;out.replaceChildren();msg.textContent='Confira o CEST para o NCM atual.';revisar();};
        ncm.addEventListener('input',invalidar);
        cestBotao.addEventListener('click',async()=>{
            const codigo=ncm.value.trim();
            if(!/^\d{8}$/.test(codigo)){msg.textContent='Selecione um NCM de oito dígitos.';return;}
            const atual=++consulta;cestBotao.disabled=true;out.replaceChildren();msg.textContent='Consultando catálogo CEST...';
            try{
                const params=new URLSearchParams({tipo:'cest',formato:'json',ncm:codigo,q:cestBusca.value.trim()});
                const r=await fetch(form.dataset.cestUrl+'?'+params,{headers:{Accept:'application/json'},credentials:'same-origin'});
                const d=await r.json();
                if(atual!==consulta || ncm.value.trim()!==codigo)return;
                if(!r.ok||!d.ok)throw new Error(d.mensagem||'Consulta indisponível.');
                msg.textContent=d.resultados.length?'Selecione após conferir descrição e segmento.':'Nenhum resultado. Confira a classificação; ausência de resultado não prova dispensa.';
                for(const item of d.resultados){
                    const b=document.createElement('button');b.type='button';b.className='btn btn-outline';b.textContent=item.codigo+' — '+item.descricao;
                    b.addEventListener('click',()=>{if(ncm.value.trim()!==codigo)return;cest.value=item.codigo;revisar();out.replaceChildren();msg.textContent='CEST selecionado. Confira antes de salvar.';});
                    out.append(b);
                }
            }catch(e){if(atual===consulta)msg.textContent=e.message||'Consulta indisponível.';}
            finally{cestBotao.disabled=false;}
        });
        cestBusca.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();cestBotao.click();}});
    }
    const gtinCampo=form.elements.namedItem('gtin'),gtinTrib=form.elements.namedItem('gtin_tributavel');
    const sem=form.querySelector('[data-fiscal-sem-gtin]');
    if(sem && gtinCampo && gtinTrib)sem.addEventListener('click',()=>{
        if((gtinCampo.value && gtinCampo.value!=='SEM GTIN')||(gtinTrib.value && gtinTrib.value!=='SEM GTIN')){
            if(!window.confirm('Confirma que o produto não possui nenhum código de barras?'))return;
        }
        gtinCampo.value=gtinTrib.value='SEM GTIN';revisar();
    });
    if(gtinCampo && gtinTrib)gtinCampo.addEventListener('change',()=>{
        if(!gtinTrib.value && gtinCampo.value)gtinTrib.value=gtinCampo.value;
        revisar();
    });
    const unidades=form.querySelector('[data-fiscal-unidades-iguais]');
    if(unidades)unidades.addEventListener('click',()=>{
        const uc=form.elements.namedItem('unidade_comercial'),ut=form.elements.namedItem('unidade_tributavel'),f=form.elements.namedItem('fator');
        if(!uc.value)return;
        if((ut.value && ut.value!==uc.value)||(f.value && !/^0*1(?:[.,]0+)?$/.test(f.value))){
            if(!window.confirm('Substituir a unidade tributável e o fator atuais?'))return;
        }
        ut.value=uc.value;f.value='1';revisar();
    });
    const gtin=form.querySelector('[data-fiscal-gtin]');
    if (gtin) gtin.addEventListener('click',()=>{if(!gtinCampo.value || gtinCampo.value===gtin.dataset.fiscalGtin || window.confirm('Substituir o código comercial atual?')){gtinCampo.value=gtin.dataset.fiscalGtin;gtinCampo.dispatchEvent(new Event('change'));}});
    const consultaGtin=form.querySelector('[data-fiscal-gtin-consultar]');
    if (consultaGtin) consultaGtin.addEventListener('click',async()=>{
        const campo=form.elements.namedItem('gtin');
        const codigo=campo.value.trim();
        const msg=form.querySelector('[data-fiscal-gtin-status]');
        const out=form.querySelector('[data-fiscal-gtin-resultado]');
        out.replaceChildren();
        if (!/^(?:\d{8}|\d{12,14})$/.test(codigo)) { msg.textContent='Informe um código de barras válido.';return; }
        consultaGtin.disabled=true; msg.textContent='Consultando NeXT...';
        try {
            const r=await fetch(form.dataset.gtinUrl,{method:'POST',headers:{Accept:'application/json'},credentials:'same-origin',body:new URLSearchParams({_csrf:form.elements.namedItem('_csrf').value,gtin:codigo})});
            const d=await r.json();
            if (!r.ok || !d.ok) throw new Error(d.mensagem||'Consulta indisponível.');
            if (campo.value.trim()!==codigo) { msg.textContent='O código foi alterado. Consulte novamente.';return; }
            msg.textContent=d.dados.fonte+' — '+d.dados.descricao;
            const b=document.createElement('button');b.type='button';b.className='btn btn-outline';
            b.textContent='Usar NCM '+d.dados.ncm+(d.dados.cest?' e CEST '+d.dados.cest:'')+' como sugestão';
            b.addEventListener('click',()=>{
                if (campo.value.trim()!==codigo) { msg.textContent='O código foi alterado. Consulte novamente.';out.replaceChildren();return; }
                const cest=form.elements.namedItem('cest');
                if ((ncm.value && ncm.value!==d.dados.ncm)||(d.dados.cest && cest.value && cest.value!==d.dados.cest)) {
                    if (!window.confirm('Substituir a classificação que está no formulário?')) return;
                }
                ncm.value=d.dados.ncm;ncm.dispatchEvent(new Event('input'));if(d.dados.cest)cest.value=d.dados.cest;
                form.elements.namedItem('confirmado').checked=false;
                msg.textContent='Sugestão carregada. Confira a classificação antes de salvar.';out.replaceChildren();
            });
            out.append(b);
        } catch(e){msg.textContent=e.message||'Consulta indisponível.';}
        finally{consultaGtin.disabled=false;}
    });
})();

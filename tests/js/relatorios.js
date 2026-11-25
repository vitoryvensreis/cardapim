'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');

function el() {
  return {value:'',hidden:false,id:'',children:[],attrs:{},handlers:{},dataset:{},
    setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},
    addEventListener(k,fn){this.handlers[k]=fn;},
    appendChild(c){this.children.push(c);},replaceChildren(){this.children=[];},
    scrollIntoView(){},focus(){},contains(t){return this===t||this.children.includes(t);}};
}
async function main(){
  const campo=el(),produto=el(),raiz=el(),painel=el(),lista=el(),msg=el(),alt=el(),hist=el();
  lista.id='rel-produtos-lista'; raiz.dataset.produtosUrl='/teste/gerencia/financeiro/relatorios/produtos';
  produto.options=[{value:''}]; produto.appendChild=function(o){this.options.push(o);};
  const mapa={'.rel-autocomplete':raiz,'[name="busca"]':campo,'[name="produto"]':produto,'.rel-produto-alternativo':alt,'[name="nome_historico"]':hist};
  const form=el(); form.querySelector=s=>mapa[s]??null;
  raiz.querySelector=s=>({'.rel-sugestoes':painel,'[role="listbox"]':lista,'.rel-sugestoes-msg':msg}[s]);
  const doc=el();doc.querySelector=()=>form;doc.createElement=()=>el();
  let chamadas=[],agendar;
  const fetch=async (url)=>{chamadas.push(url);return {ok:true,json:async()=>({produtos:[{id:'42',nome:'Heineken 600 ml',categoria:'Cervejas',ativo:true}]})};};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/assets/js/relatorios.js'),'utf8'),{
    document:doc,fetch,URLSearchParams,AbortController,setTimeout:fn=>{agendar=fn;return 1;},clearTimeout:()=>{agendar=null;}
  });
  assert.equal(alt.hidden,true);
  produto.value='12'; hist.value='Antigo'; campo.value='h';campo.handlers.input();
  assert.equal(produto.value,'');assert.equal(hist.value,'');assert.equal(chamadas.length,0);
  campo.value='hei';campo.handlers.input(); await agendar();
  assert.match(chamadas[0],/q=hei/);assert.equal(lista.children.length,1);
  campo.handlers.keydown({key:'ArrowDown',preventDefault(){}});
  assert.equal(campo.attrs['aria-activedescendant'],'rel-produtos-lista-0');
  campo.handlers.keydown({key:'Enter',preventDefault(){}});
  assert.equal(produto.value,'42');assert.equal(campo.value,'Heineken 600 ml');assert.equal(painel.hidden,true);
  campo.value='bra';campo.handlers.input();assert.equal(produto.value,'');
  campo.handlers.keydown({key:'Escape'});assert.equal(painel.hidden,true);
  console.log('Relatórios JS: sugestões automáticas, seleção exata, teclado e pesquisa livre.');
}
main().catch(e=>{console.error(e);process.exitCode=1;});

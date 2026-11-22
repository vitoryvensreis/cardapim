package br.com.deliveryzim.virtualscale;

import java.io.ByteArrayOutputStream;
import java.nio.charset.Charset;

/** Teste java puro do leitor ESC/POS da impressora virtual (roda no CI antes do APK). */
public final class EscPosTest {
    private static int falhas;

    private static void ok(boolean cond, String nome) {
        System.out.println((cond ? "  ok  " : "  FALHOU  ") + nome);
        if (!cond) falhas++;
    }

    private static byte[] b(int... v) { byte[] r = new byte[v.length]; for (int i = 0; i < v.length; i++) r[i] = (byte) v[i]; return r; }

    public static void main(String[] args) throws Exception {
        Charset cp850 = Charset.forName("IBM850");
        ok(true, "página 850 do Java disponível no teste");
        boolean tabela = true;
        for (int x = 0x80; x <= 0xFF; x++) {
            String esperado = new String(new byte[]{(byte) x}, cp850);
            if (EscPos.caractere(x) != esperado.charAt(0)) { tabela = false; System.out.println("    diferente em 0x" + Integer.toHexString(x) + ": " + EscPos.caractere(x) + " / " + esperado); }
        }
        ok(tabela, "tabela CP850 igual à do Java (acentos do cupom em texto)");

        // Cupom em texto (Agent antigo): título centralizado e duplo, negrito, acentos, avanço e corte.
        ByteArrayOutputStream t = new ByteArrayOutputStream();
        t.write(b(0x1B, '@', 0x1B, 'a', 1, 0x1B, '!', 0x30));
        t.write("MESA 18\n".getBytes(cp850));
        t.write(b(0x1B, '!', 0, 0x1B, 'a', 0, 0x1B, 'E', 1));
        t.write("Ação 2x".getBytes(cp850));
        t.write(b(0x1B, 'E', 0));
        t.write(" R$ 10,00\n\n".getBytes(cp850));
        t.write(b(0x1B, 'd', 4, 0x1D, 'V', 0));
        EscPos.Resultado r = EscPos.ler(t.toByteArray());
        ok(r.cupons.size() == 1 && r.cupons.get(0).cortado, "um cupom, terminado no corte");
        EscPos.Cupom c = r.cupons.get(0);
        EscPos.Parte titulo = c.partes.get(0);
        ok(titulo.tipo == EscPos.Parte.TEXTO && titulo.alinhamento == 1, "título centralizado");
        ok(titulo.trechos.get(0).texto.equals("MESA 18") && titulo.trechos.get(0).largura == 2 && titulo.trechos.get(0).altura == 2, "título com letra dupla");
        EscPos.Parte item = c.partes.get(1);
        ok(item.trechos.size() == 2 && item.trechos.get(0).negrito && !item.trechos.get(1).negrito, "negrito só no começo da linha");
        ok(item.trechos.get(0).texto.equals("Ação 2x"), "acentos em CP850 lidos certo");
        ok(c.partes.get(2).tipo == EscPos.Parte.AVANCO && c.partes.get(3).tipo == EscPos.Parte.AVANCO && c.partes.get(3).linhas == 4, "linha em branco e espaço antes do corte");
        ok(r.desconhecidos == 0, "nenhum comando desconhecido");

        // Cupom em imagem (Agent 0.9+): duas faixas GS v 0 de 576 pontos viram uma imagem só.
        ByteArrayOutputStream im = new ByteArrayOutputStream();
        im.write(b(0x1B, '@', 0x1B, 'a', 1));
        for (int faixa = 0; faixa < 2; faixa++) {
            im.write(b(0x1D, 'v', '0', 0, 72, 0, 128, 0));
            byte[] dados = new byte[72 * 128];
            if (faixa == 0) for (int k = 0; k < 72; k++) dados[k] = (byte) 0xFF; // primeira linha preta
            im.write(dados);
        }
        im.write(b(0x1B, 'a', 0, 0x1D, 'V', 0));
        r = EscPos.ler(im.toByteArray());
        ok(r.cupons.size() == 1, "cupom em imagem");
        c = r.cupons.get(0);
        EscPos.Parte img = null;
        for (EscPos.Parte p : c.partes) if (p.tipo == EscPos.Parte.IMAGEM) img = p;
        ok(img != null && img.larguraPontos == 576 && img.alturaPontos == 256, "faixas juntas: 576 x 256 pontos");
        ok(img != null && img.preto(0, 0) && img.preto(575, 0) && !img.preto(0, 1), "pontos pretos no lugar certo");
        ok(c.larguraImagem == 576, "descobre o papel de 80 mm pela imagem");

        // Dois cupons no mesmo envio, gaveta e status no meio, e sobra sem corte.
        ByteArrayOutputStream dois = new ByteArrayOutputStream();
        dois.write("A\n".getBytes(cp850));
        dois.write(b(0x1D, 'V', 66, 3, 0x1B, 'p', 0, 25, 250, 0x10, 0x04, 1));
        dois.write("B\n".getBytes(cp850));
        dois.write(b(0x1D, 'V', 1));
        dois.write("C sem corte".getBytes(cp850));
        r = EscPos.ler(dois.toByteArray());
        ok(r.cupons.size() == 3, "três cupons (dois cortados e um sem corte)");
        ok(r.cupons.get(0).cortado && r.cupons.get(1).cortado && !r.cupons.get(2).cortado, "marca qual foi cortado");
        ok(r.cupons.get(1).partes.get(0).trechos.get(0).texto.equals("B"), "gaveta e status não viram texto");

        // Dados cortados no meio da imagem não travam.
        r = EscPos.ler(b(0x1D, 'v', '0', 0, 10, 0, 50, 0, 1, 2, 3));
        ok(r.cupons.size() == 1, "imagem incompleta não quebra a leitura");

        if (falhas > 0) { System.out.println(falhas + " falha(s)."); System.exit(1); }
        System.out.println("Impressora virtual: leitor ESC/POS ok.");
    }
}

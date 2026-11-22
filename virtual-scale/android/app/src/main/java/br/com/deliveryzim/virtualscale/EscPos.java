package br.com.deliveryzim.virtualscale;

import java.util.ArrayList;
import java.util.List;

/**
 * Leitor de ESC/POS para a impressora virtual (sem Android: testável com java puro).
 * Transforma os bytes que o Deliveryzim Agent manda para a térmica em uma lista de cupons; cada cupom é uma lista
 * de partes (linha de texto, imagem raster GS v 0, avanço de papel) e termina no corte (GS V).
 * Comandos que não mudam o desenho (gaveta, página de código, status) são lidos e ignorados para não virar lixo.
 */
public final class EscPos {
    private EscPos() {}

    /** Pedaço de texto com o mesmo estilo dentro de uma linha. */
    public static final class Trecho {
        public final String texto;
        public final boolean negrito;
        public final int largura;
        public final int altura;
        Trecho(String texto, boolean negrito, int largura, int altura) {
            this.texto = texto; this.negrito = negrito; this.largura = largura; this.altura = altura;
        }
    }

    /** Parte do cupom: TEXTO (trechos + alinhamento), IMAGEM (bits 1 = preto) ou AVANCO (linhas em branco). */
    public static final class Parte {
        public static final int TEXTO = 1, IMAGEM = 2, AVANCO = 3;
        public final int tipo;
        public final List<Trecho> trechos;
        public final int alinhamento; // 0 esquerda, 1 centro, 2 direita
        public final int larguraPontos;
        public final int alturaPontos;
        public final byte[] bits;
        public final int linhas;
        private Parte(int tipo, List<Trecho> trechos, int alinhamento, int larguraPontos, int alturaPontos, byte[] bits, int linhas) {
            this.tipo = tipo; this.trechos = trechos; this.alinhamento = alinhamento; this.larguraPontos = larguraPontos;
            this.alturaPontos = alturaPontos; this.bits = bits; this.linhas = linhas;
        }
        static Parte texto(List<Trecho> t, int a) { return new Parte(TEXTO, t, a, 0, 0, null, 0); }
        static Parte imagem(int w, int h, byte[] b, int a) { return new Parte(IMAGEM, null, a, w, h, b, 0); }
        static Parte avanco(int n) { return new Parte(AVANCO, null, 0, 0, 0, null, n); }

        /** Ponto (x, y) da imagem é preto? */
        public boolean preto(int x, int y) {
            int porLinha = (larguraPontos + 7) / 8;
            int i = y * porLinha + x / 8;
            return i >= 0 && i < bits.length && (bits[i] & (0x80 >> (x % 8))) != 0;
        }
    }

    /** Um cupom (do início até o corte). */
    public static final class Cupom {
        public final List<Parte> partes = new ArrayList<>();
        public boolean cortado;
        /** Maior largura de imagem recebida (ajuda a descobrir o papel: 384 = 58 mm, 576 = 80 mm). */
        public int larguraImagem;
        public boolean vazio() {
            for (Parte p : partes) if (p.tipo != Parte.AVANCO) return false;
            return true;
        }
    }

    /** Resultado da leitura: cupons e comandos desconhecidos (para o diagnóstico na tela). */
    public static final class Resultado {
        public final List<Cupom> cupons = new ArrayList<>();
        public int desconhecidos;
    }

    // Página de código 850 (a que o Agent usa no texto): bytes 0x80 a 0xFF.
    private static final String CP850 =
            "ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜø£Ø×ƒáíóúñÑªº¿®¬½¼¡«»░▒▓│┤ÁÂÀ©╣║╗╝¢¥┐└┴┬├─┼ãÃ╚╔╩╦╠═╬¤ðÐÊËÈıÍÎÏ┘┌█▄¦Ì▀"
            + "ÓßÔÒõÕµþÞÚÛÙýÝ¯´­±‗¾¶§÷¸°¨·¹³²■ ";

    static char caractere(int b) {
        if (b < 0x80) return (char) b;
        int i = b - 0x80;
        return i < CP850.length() ? CP850.charAt(i) : '?';
    }

    public static Resultado ler(byte[] dados) {
        return new Leitor(dados).ler();
    }

    private static final class Leitor {
        private final byte[] d;
        private int i;
        private final Resultado r = new Resultado();
        private Cupom cupom = new Cupom();
        private final List<Trecho> linha = new ArrayList<>();
        private final StringBuilder atual = new StringBuilder();
        private int alinhamentoLinha = -1;
        private int alinhamento;
        private boolean negrito;
        private boolean negritoModo; // ESC ! bit 3
        private int largura = 1;
        private int altura = 1;

        Leitor(byte[] d) { this.d = d; }

        private int u(int pos) { return pos < d.length ? d[pos] & 0xFF : -1; }

        private boolean forte() { return negrito || negritoModo; }

        private void fecharTrecho() {
            if (atual.length() > 0) {
                linha.add(new Trecho(atual.toString(), forte(), largura, altura));
                atual.setLength(0);
            }
        }

        private void quebrarLinha() {
            fecharTrecho();
            if (linha.isEmpty()) {
                cupom.partes.add(Parte.avanco(1));
            } else {
                cupom.partes.add(Parte.texto(new ArrayList<>(linha), alinhamentoLinha < 0 ? alinhamento : alinhamentoLinha));
                linha.clear();
            }
            alinhamentoLinha = -1;
        }

        private void mudarEstilo(Runnable mudanca) {
            fecharTrecho();
            mudanca.run();
        }

        private void cortar() {
            if (atual.length() > 0 || !linha.isEmpty()) quebrarLinha();
            cupom.cortado = true;
            r.cupons.add(cupom);
            cupom = new Cupom();
        }

        private void reiniciar() {
            fecharTrecho();
            alinhamento = 0; negrito = false; negritoModo = false; largura = 1; altura = 1;
        }

        Resultado ler() {
            while (i < d.length) {
                int b = u(i);
                if (b == 0x1B) esc();
                else if (b == 0x1D) gs();
                else if (b == 0x10 && (u(i + 1) == 0x04 || u(i + 1) == 0x05)) i += 3; // DLE EOT/ENQ: status
                else if (b == 0x10 && u(i + 1) == 0x14) i += 5; // DLE DC4: gaveta em tempo real
                else if (b == 0x1C) i += 2; // FS (kanji/página): ignorado
                else if (b == 0x0A) { quebrarLinha(); i++; }
                else if (b == 0x09) { atual.append("    "); i++; }
                else if (b == 0x0C) { cortar(); i++; } // FF em modo página: termina o cupom
                else if (b < 0x20) i++;
                else {
                    if (atual.length() == 0 && linha.isEmpty() && alinhamentoLinha < 0) alinhamentoLinha = alinhamento;
                    atual.append(caractere(b));
                    i++;
                }
            }
            if (atual.length() > 0 || !linha.isEmpty()) quebrarLinha();
            if (!cupom.vazio()) r.cupons.add(cupom); // sobrou sem corte
            return r;
        }

        private void esc() {
            int c = u(i + 1);
            switch (c) {
                case '@': reiniciar(); i += 2; break;
                case 'a': { int n = u(i + 2); final int a = (n == '1' || n == 1) ? 1 : (n == '2' || n == 2) ? 2 : 0;
                    mudarEstilo(() -> alinhamento = a); if (atual.length() == 0 && linha.isEmpty()) alinhamentoLinha = -1; i += 3; break; }
                case 'E': { final boolean on = (u(i + 2) & 1) == 1; mudarEstilo(() -> negrito = on); i += 3; break; }
                case 'G': { final boolean on = (u(i + 2) & 1) == 1; mudarEstilo(() -> negrito = on); i += 3; break; }
                case '!': { final int n = u(i + 2); mudarEstilo(() -> {
                    negritoModo = (n & 0x08) != 0; altura = (n & 0x10) != 0 ? 2 : 1; largura = (n & 0x20) != 0 ? 2 : 1; });
                    i += 3; break; }
                case 'd': { int n = Math.max(0, u(i + 2)); fecharLinhaSePreciso(); cupom.partes.add(Parte.avanco(n)); i += 3; break; }
                case 'J': { int n = Math.max(0, u(i + 2)); fecharLinhaSePreciso(); cupom.partes.add(Parte.avanco(Math.max(1, n / 30))); i += 3; break; }
                case 'p': i += 5; break; // abrir gaveta
                case '*': { // imagem em colunas (antiga): pula os dados
                    int m = u(i + 2); int n = u(i + 3) + u(i + 4) * 256; int porColuna = (m == 32 || m == 33) ? 3 : 1;
                    i += 5 + n * porColuna; r.desconhecidos++; break; }
                case 't': case '-': case '2': case 'M': case 'R': case 'V': case '{': case 'c': case 'r': case 'U': case 'S':
                    i += (c == '2' || c == 'S') ? 2 : 3; break;
                case '3': case ' ': case '$': case '\\': i += (c == '$' || c == '\\') ? 4 : 3; break;
                default: r.desconhecidos++; i += 2;
            }
        }

        private void fecharLinhaSePreciso() {
            if (atual.length() > 0 || !linha.isEmpty()) quebrarLinha();
        }

        private void gs() {
            int c = u(i + 1);
            switch (c) {
                case 'V': { int m = u(i + 2); cortar(); i += (m == 65 || m == 66) ? 4 : 3; break; }
                case '!': { final int n = Math.max(0, u(i + 2)); mudarEstilo(() -> {
                    largura = Math.min(8, ((n >> 4) & 0x0F) + 1); altura = Math.min(8, (n & 0x0F) + 1); }); i += 3; break; }
                case 'v': { // GS v 0 m xL xH yL yH dados: imagem raster
                    if (u(i + 2) != '0') { r.desconhecidos++; i += 2; break; }
                    int xBytes = u(i + 4) + u(i + 5) * 256;
                    int yPontos = u(i + 6) + u(i + 7) * 256;
                    int tamanho = Math.max(0, xBytes * yPontos);
                    int inicio = i + 8;
                    byte[] bits = new byte[tamanho];
                    System.arraycopy(d, Math.min(inicio, d.length), bits, 0, Math.max(0, Math.min(tamanho, d.length - inicio)));
                    fecharLinhaSePreciso();
                    juntarImagem(xBytes * 8, yPontos, bits);
                    i = inicio + tamanho;
                    break;
                }
                case 'B': case 'H': case 'f': case 'h': case 'w': case 'b': case 'I': case 'r': case 'a': i += 3; break;
                case 'L': case 'W': case 'P': i += 4; break;
                case 'k': { // código de barras: pula
                    int m = u(i + 2);
                    if (m <= 6) { int j = i + 3; while (j < d.length && d[j] != 0) j++; i = j + 1; }
                    else i += 4 + u(i + 3);
                    r.desconhecidos++; break; }
                case '(': { int n = u(i + 3) + u(i + 4) * 256; i += 5 + n; break; }
                default: r.desconhecidos++; i += 2;
            }
        }

        /** Faixas seguidas da mesma imagem (o Agent manda em faixas de 128 linhas) viram uma imagem só. */
        private void juntarImagem(int w, int h, byte[] bits) {
            cupom.larguraImagem = Math.max(cupom.larguraImagem, w);
            int ultimo = cupom.partes.size() - 1;
            if (ultimo >= 0) {
                Parte anterior = cupom.partes.get(ultimo);
                if (anterior.tipo == Parte.IMAGEM && anterior.larguraPontos == w) {
                    byte[] junto = new byte[anterior.bits.length + bits.length];
                    System.arraycopy(anterior.bits, 0, junto, 0, anterior.bits.length);
                    System.arraycopy(bits, 0, junto, anterior.bits.length, bits.length);
                    cupom.partes.set(ultimo, Parte.imagem(w, anterior.alturaPontos + h, junto, alinhamento));
                    return;
                }
            }
            cupom.partes.add(Parte.imagem(w, h, bits, alinhamento));
        }
    }
}

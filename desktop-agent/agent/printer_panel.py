"""Cartão "Impressoras" da tela de configuração: lista sem limite, cada uma com apelido, impressora do Windows e papel.

A primeira é a principal (imprime o que não tem impressora escolhida). O gerente decide no painel web
(Gerência > Integrações > Impressoras) em qual impressora cada parte do sistema imprime.
"""
import tkinter as tk
from tkinter import messagebox

import customtkinter as ctk

from agent.printer_list import MAX_ALIAS, MAX_PRINTERS, alias_of, new_printer, problems

WINE = "#651424"
GRAY = "#6C6770"
DARK = "#272727"
WHITE = "#FFFFFF"
BORDER = "#E8E3E4"
SUBTLE = "#F8F4F4"


class PrinterRow:
    def __init__(self, panel, printer, index):
        self.panel = panel
        self.printer = dict(printer)
        ui = panel.ui
        self.frame = ctk.CTkFrame(panel.rows, fg_color=SUBTLE, corner_radius=6, border_width=1, border_color=BORDER)
        top = ctk.CTkFrame(self.frame, fg_color=SUBTLE, corner_radius=0)
        top.pack(fill="x", padx=12, pady=(10, 4))
        self.title = ui._label(top, "", 10, DARK, True, fg_color=SUBTLE)
        self.title.pack(side="left")
        self.remove_button = ui._button(top, "Remover", self.remove, primary=False, width=90, height=28, text_color=GRAY)
        self.remove_button.pack(side="right")
        ui._button(top, "Testar", self.test, primary=False, width=80, height=28).pack(side="right", padx=(0, 6))

        self.alias = tk.StringVar(value=self.printer.get("apelido") or "")
        ui._label(self.frame, "Apelido (aparece no sistema)", 9, GRAY, fg_color=SUBTLE).pack(anchor="w", padx=12)
        self.alias_entry = ui._entry(self.frame, textvariable=self.alias, placeholder_text="Ex.: Balcão, Cozinha, Bar")
        self.alias_entry.pack(fill="x", padx=12, pady=(2, 8))
        self.alias_entry.bind("<FocusOut>", lambda _e: self.panel.changed())
        self.alias_entry.bind("<Return>", lambda _e: self.panel.changed())

        ui._label(self.frame, "Impressora do Windows", 9, GRAY, fg_color=SUBTLE).pack(anchor="w", padx=12)
        self.windows = tk.StringVar(value=self.printer.get("windows") or "")
        self.selector = ctk.CTkComboBox(
            self.frame, variable=self.windows, values=list(panel.available), state="readonly", height=38,
            corner_radius=6, border_width=1, border_color=BORDER, button_color=WHITE,
            button_hover_color=BORDER, fg_color=WHITE, text_color=DARK, font=ui._font(10),
            dropdown_font=ui._font(10), command=lambda _v: self.panel.changed(),
        )
        self.selector.pack(fill="x", padx=12, pady=(2, 8))

        row = ctk.CTkFrame(self.frame, fg_color=SUBTLE, corner_radius=0)
        row.pack(fill="x", padx=12, pady=(0, 10))
        ui._label(row, "Papel", 9, GRAY, fg_color=SUBTLE).pack(side="left")
        self.paper = tk.StringVar(value=str(self.printer.get("papel") or 80) + " mm")
        self.paper_choice = ui._segmented(row, ("58 mm", "80 mm"), self.paper, lambda _l: self.panel.changed())
        self.paper_choice.pack(side="right")
        self.set_index(index)

    def set_index(self, index):
        self.index = index
        self.title.configure(text=("Principal · " if index == 0 else "") + alias_of(self.value(), index))

    def value(self):
        return {
            "chave": self.printer["chave"],
            "apelido": " ".join(self.alias.get().split())[:MAX_ALIAS],
            "windows": self.windows.get(),
            "papel": 58 if self.paper.get().startswith("58") else 80,
        }

    def set_available(self, names):
        self.selector.configure(values=list(names))
        if self.windows.get() and self.windows.get() not in names:
            self.windows.set("")

    def remove(self):
        if len(self.panel.row_list) == 1:
            messagebox.showinfo("Impressoras", "Deixe pelo menos uma impressora (a principal).")
            return
        if messagebox.askyesno("Impressoras", "Tirar \"" + alias_of(self.value(), self.index) + "\" deste computador?\n"
                               "Os pontos do sistema que usavam ela voltam para a impressora principal."):
            self.panel.remove(self)

    def test(self):
        self.panel.changed()
        self.panel.ui._test_printer(self.value())


class PrinterPanel:
    def __init__(self, parent, printers, ui, on_change):
        self.ui = ui
        self.on_change = on_change
        self.available = []
        self.row_list = []
        self.frame = ctk.CTkFrame(parent, fg_color=WHITE, corner_radius=0)
        head = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        head.pack(fill="x")
        ui._label(head, "Impressoras", 12, DARK, True).pack(side="left")
        ui._button(head, "Atualizar lista", ui._reload_printers, primary=False, width=120, height=30).pack(side="right")
        ui._label(self.frame, "Cadastre quantas quiser, com apelido (Balcão, Cozinha...). No painel, em Gerência > "
                  "Integrações > Impressoras, você escolhe onde cada parte do sistema imprime.", 9, GRAY,
                  wrap=520).pack(anchor="w", pady=(2, 8))
        self.rows = ctk.CTkFrame(self.frame, fg_color=WHITE, corner_radius=0)
        self.rows.pack(fill="x")
        self.hint = ui._label(self.frame, "", 9, GRAY, wrap=520)
        self.hint.pack(fill="x", pady=(6, 0))
        self.add_button = ui._button(self.frame, "+ Adicionar impressora", self.add, primary=False)
        self.add_button.pack(fill="x", pady=(8, 0))
        for printer in printers:
            self._append(printer)

    def _append(self, printer):
        row = PrinterRow(self, printer, len(self.row_list))
        row.frame.pack(fill="x", pady=(0, 8))
        self.row_list.append(row)
        return row

    def add(self):
        if len(self.row_list) >= MAX_PRINTERS:
            messagebox.showinfo("Impressoras", "Limite de " + str(MAX_PRINTERS) + " impressoras por computador.")
            return
        row = self._append(new_printer("", self.available[0] if len(self.available) == 1 else "", 80))
        row.alias_entry.focus_set()
        self.changed()

    def remove(self, row):
        row.frame.destroy()
        self.row_list.remove(row)
        for i, r in enumerate(self.row_list):
            r.set_index(i)
        self.changed()

    def values(self):
        return [r.value() for r in self.row_list]

    def set_available(self, names, message="", error=False):
        self.available = list(names)
        for row in self.row_list:
            row.set_available(names)
        self.show_hint(message, error)

    def show_hint(self, message="", error=False):
        notes = problems(self.values())
        text = message if (error or not notes) else " ".join(notes[:2])
        self.hint.configure(text=text, text_color=WINE if (error or notes) else GRAY)

    def changed(self):
        for row in self.row_list:
            row.set_index(row.index)
        self.show_hint(str(len(self.available)) + " impressora(s) do Windows encontrada(s).")
        self.on_change()

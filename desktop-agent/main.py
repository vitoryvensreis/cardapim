"""Entrada do Deliveryzim Agent para Windows."""
import multiprocessing
from agent.app import DeliveryzimApp

if __name__ == "__main__":
    multiprocessing.freeze_support()
    DeliveryzimApp().run()

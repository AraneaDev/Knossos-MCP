#!/usr/bin/env python3
"""A console script: run by a shell, imported by nothing."""

from shop.service import CheckoutService

CheckoutService().checkout()

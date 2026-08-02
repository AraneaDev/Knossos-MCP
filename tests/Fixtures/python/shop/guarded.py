"""A module that is also runnable: `python -m shop.guarded`."""

from shop.service import CheckoutService


def main() -> int:
    CheckoutService().checkout()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

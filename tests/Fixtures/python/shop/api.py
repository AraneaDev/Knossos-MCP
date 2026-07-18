from .service import CheckoutService as Service


@router.get("/checkout")
async def checkout_endpoint() -> None:
    Service()

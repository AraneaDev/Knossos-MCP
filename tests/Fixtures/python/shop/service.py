from typing import Protocol


class Gateway(Protocol):
    def charge(self) -> None: ...


@registered("checkout")
class CheckoutService(Gateway):
    async def checkout(self) -> None:
        self.validate()

    def validate(self) -> None:
        return None

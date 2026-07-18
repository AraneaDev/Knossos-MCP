def first() -> None:
    def helper() -> None:
        helper()

    helper()


def second() -> None:
    async def helper() -> None:
        async def deeper() -> None:
            await helper()

        await deeper()

    helper()

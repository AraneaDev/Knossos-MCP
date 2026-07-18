from fastapi import APIRouter, Depends, FastAPI

from .dependencies import load_user, require_admin

app = FastAPI()
router = APIRouter(prefix="/api")


class AuthenticationMiddleware:
    pass


@router.get("/orders", dependencies=[Depends(require_admin)])
async def list_orders(user=Depends(load_user)) -> None:
    pass


@router.post(dynamic_path)
def dynamic_route() -> None:
    pass


app.include_router(router, prefix="/v1")
app.add_middleware(AuthenticationMiddleware)

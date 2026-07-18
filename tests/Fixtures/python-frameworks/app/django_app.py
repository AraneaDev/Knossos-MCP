from celery import shared_task
from django.db import models
from django.urls import path
from django.views import View


def checkout_view(request) -> None:
    pass


class ProductView(View):
    pass


class Product(models.Model):
    pass


class AuditMiddleware:
    def __init__(self, get_response) -> None:
        self.get_response = get_response

    def __call__(self, request):
        return self.get_response(request)


@shared_task
def reconcile_orders() -> None:
    pass


urlpatterns = [
    path("checkout/", checkout_view, name="checkout"),
    path("products/", ProductView.as_view()),
    path(dynamic_path, checkout_view),
]

INSTALLED_APPS = ["app"]
MIDDLEWARE = ["app.django_app.AuditMiddleware"]
ROOT_URLCONF = "app.django_app"
SECRET_KEY = runtime_secret()

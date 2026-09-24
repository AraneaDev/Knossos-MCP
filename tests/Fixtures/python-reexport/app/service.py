from app import config


def run():
    return config.staging_dir(), config.request_window()
